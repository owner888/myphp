import Foundation
import AVFoundation

final class GeminiAudioPipeline: NSObject {
    private let engine = AVAudioEngine()
    private var apiKey: String
    private var targetLang: String
    private var targetLangName: String
    
    private var isRunning: Bool = false
    private var isSpeaking: Bool = false
    private var pcmData = Data()
    
    // Adaptive VAD parameters
    private var noiseFloor: Float = 0.005
    private var silenceStartTime: TimeInterval = 0
    private let silenceTimeout: TimeInterval = 0.32 // 320ms agile endpointing
    private let minSpeechFrames: Int = 16000 * 3 / 10 // at least 300ms speech
    private let maxSpeechFrames: Int = 16000 * 8      // max 8 seconds per chunk
    
    init(apiKey: String, targetLang: String = "auto") {
        self.apiKey = apiKey
        self.targetLang = targetLang
        self.targetLangName = Self.mapLangName(targetLang)
        super.init()
    }
    
    private static func mapLangName(_ code: String) -> String {
        switch code {
        case "auto": return "auto"
        case "zh-CN": return "Simplified Chinese"
        case "en-US": return "English"
        case "ja-JP": return "Japanese"
        case "ko-KR": return "Korean"
        case "fr-FR": return "French"
        case "de-DE": return "German"
        case "es-ES": return "Spanish"
        default: return "English"
        }
    }
    
    func start() {
        guard !isRunning else { return }
        let input = engine.inputNode
        let hwFormat = input.inputFormat(forBus: 0)
        
        // Target: 16000Hz Mono 16-bit Linear PCM
        guard let targetFormat = AVAudioFormat(commonFormat: .pcmFormatInt16, sampleRate: 16000, channels: 1, interleaved: false),
              let converter = AVAudioConverter(from: hwFormat, to: targetFormat) else {
            sendJSON(["type": "error", "message": "Failed to create audio converter for 16kHz"])
            return
        }
        
        input.removeTap(onBus: 0)
        input.installTap(onBus: 0, bufferSize: 1024, format: hwFormat) { [weak self] buffer, _ in
            guard let self = self, self.isRunning else { return }
            
            let frameCount = AVAudioFrameCount(Double(buffer.frameLength) * 16000.0 / hwFormat.sampleRate) + 32
            guard let converted = AVAudioPCMBuffer(pcmFormat: targetFormat, frameCapacity: frameCount) else { return }
            
            var err: NSError?
            converter.convert(to: converted, error: &err) { _, status in
                status.pointee = .haveData
                return buffer
            }
            
            self.processConvertedPCM(converted)
        }
        
        engine.prepare()
        do {
            try engine.start()
            isRunning = true
            sendJSON(["type": "status", "status": "listening", "target": targetLang])
        } catch {
            sendJSON(["type": "error", "message": error.localizedDescription])
        }
    }
    
    private func processConvertedPCM(_ buffer: AVAudioPCMBuffer) {
        guard let channelData = buffer.int16ChannelData?[0] else { return }
        let frames = Int(buffer.frameLength)
        guard frames > 0 else { return }
        
        var sum: Double = 0
        for i in 0..<frames {
            let v = Double(channelData[i]) / 32768.0
            sum += v * v
        }
        let rms = Float(sqrt(sum / Double(frames)))
        let now = Date().timeIntervalSince1970
        
        // Dynamic threshold: adaptive tracking of background floor
        let voiceThreshold = max(0.012, noiseFloor * 2.8)
        
        if rms >= voiceThreshold {
            if !isSpeaking {
                isSpeaking = true
                pcmData.removeAll(keepingCapacity: true)
                sendJSON(["type": "vad", "state": "start"])
            }
            silenceStartTime = 0
            pcmData.append(UnsafeBufferPointer(start: channelData, count: frames))
            
            if pcmData.count >= maxSpeechFrames * 2 {
                flushCurrentSegment()
            }
        } else {
            noiseFloor = noiseFloor * 0.95 + rms * 0.05
            
            if isSpeaking {
                pcmData.append(UnsafeBufferPointer(start: channelData, count: frames))
                if silenceStartTime == 0 {
                    silenceStartTime = now
                } else if now - silenceStartTime > silenceTimeout {
                    flushCurrentSegment()
                }
            }
        }
    }
    
    private func flushCurrentSegment() {
        isSpeaking = false
        silenceStartTime = 0
        sendJSON(["type": "vad", "state": "end"])
        
        let chunk = pcmData
        pcmData.removeAll(keepingCapacity: true)
        
        if chunk.count >= minSpeechFrames * 2 {
            sendToGemini(pcmChunk: chunk)
        }
    }
    
    private func sendToGemini(pcmChunk: Data) {
        let wavData = createWavData(from: pcmChunk, sampleRate: 16000, channels: 1, bitsPerSample: 16)
        let b64 = wavData.base64EncodedString()
        
        let urlString = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=\(apiKey)"
        guard let url = URL(string: urlString) else { return }
        
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.timeoutInterval = 8
        
        let prompt: String
        if targetLang == "auto" {
            prompt = "Automatically detect the spoken language. Transcribe the audio verbatim in its original language. If the spoken language is Chinese, translate it into English. If English or any other language, translate it into Simplified Chinese. Return strictly raw JSON: {\"detected_lang\": \"<zh|en|other>\", \"transcript\": \"<original text>\", \"translation\": \"<translated text>\"}. Do not use markdown formatting."
        } else {
            prompt = "Automatically detect the spoken language. Transcribe the audio verbatim in its original language. Translate it into \(targetLangName). Return strictly raw JSON: {\"detected_lang\": \"<language code>\", \"transcript\": \"<original text>\", \"translation\": \"<translated text>\"}. Do not use markdown formatting."
        }
        
        let payload: [String: Any] = [
            "contents": [[
                "parts": [
                    ["inline_data": ["mime_type": "audio/wav", "data": b64]],
                    ["text": prompt]
                ]
            ]],
            "generationConfig": [
                "response_mime_type": "application/json",
                "temperature": 0.0
            ]
        ]
        
        request.httpBody = try? JSONSerialization.data(withJSONObject: payload)
        
        URLSession.shared.dataTask(with: request) { [weak self] data, response, error in
            guard let self = self, let data = data else { return }
            
            guard let root = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
                  let candidates = root["candidates"] as? [[String: Any]],
                  let parts = (candidates.first?["content"] as? [String: Any])?["parts"] as? [[String: Any]],
                  let text = parts.first?["text"] as? String else {
                return
            }
            
            if let jsonObj = (try? JSONSerialization.jsonObject(with: Data(text.utf8))) as? [String: Any] {
                let transcript = (jsonObj["transcript"] as? String ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
                let translation = (jsonObj["translation"] as? String ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
                let detectedLang = (jsonObj["detected_lang"] as? String ?? "")
                
                if !transcript.isEmpty {
                    self.sendJSON([
                        "type": "speech",
                        "detected_lang": detectedLang,
                        "text": transcript,
                        "translated": translation
                    ])
                }
            }
        }.resume()
    }
    
    private func createWavData(from pcm: Data, sampleRate: Int32, channels: Int16, bitsPerSample: Int16) -> Data {
        var header = Data()
        let byteRate = sampleRate * Int32(channels * (bitsPerSample / 8))
        let blockAlign = channels * (bitsPerSample / 8)
        let totalDataLen = Int32(pcm.count)
        let totalChunkLen = totalDataLen + 36
        
        header.append(contentsOf: "RIFF".utf8)
        header.append(withUnsafeBytes(of: totalChunkLen.littleEndian) { Data($0) })
        header.append(contentsOf: "WAVE".utf8)
        header.append(contentsOf: "fmt ".utf8)
        
        let subchunk1Size: Int32 = 16
        let audioFormat: Int16 = 1 // PCM
        header.append(withUnsafeBytes(of: subchunk1Size.littleEndian) { Data($0) })
        header.append(withUnsafeBytes(of: audioFormat.littleEndian) { Data($0) })
        header.append(withUnsafeBytes(of: channels.littleEndian) { Data($0) })
        header.append(withUnsafeBytes(of: sampleRate.littleEndian) { Data($0) })
        header.append(withUnsafeBytes(of: byteRate.littleEndian) { Data($0) })
        header.append(withUnsafeBytes(of: blockAlign.littleEndian) { Data($0) })
        header.append(withUnsafeBytes(of: bitsPerSample.littleEndian) { Data($0) })
        
        header.append(contentsOf: "data".utf8)
        header.append(withUnsafeBytes(of: totalDataLen.littleEndian) { Data($0) })
        
        var full = header
        full.append(pcm)
        return full
    }
    
    func stop() {
        guard isRunning else { return }
        isRunning = false
        engine.stop()
        engine.inputNode.removeTap(onBus: 0)
        sendJSON(["type": "status", "status": "stopped"])
    }
    
    private func sendJSON(_ dict: [String: Any]) {
        if let data = try? JSONSerialization.data(withJSONObject: dict),
           let str = String(data: data, encoding: .utf8) {
            print(str)
            fflush(stdout)
        }
    }
}

guard let apiKey = ProcessInfo.processInfo.environment["GEMINI_API_KEY"], !apiKey.isEmpty else {
    print("{\"type\":\"error\",\"message\":\"GEMINI_API_KEY required\"}")
    exit(1)
}

var targetLang = "auto"
let args = CommandLine.arguments
if let idx = args.firstIndex(of: "--target"), idx + 1 < args.count {
    targetLang = args[idx + 1]
}

let pipeline = GeminiAudioPipeline(apiKey: apiKey, targetLang: targetLang)
pipeline.start()

signal(SIGINT) { _ in exit(0) }
signal(SIGTERM) { _ in exit(0) }

let stdinSource = DispatchSource.makeReadSource(fileDescriptor: fileno(stdin), queue: DispatchQueue.global(qos: .background))
stdinSource.setEventHandler {
    var buf = [UInt8](repeating: 0, count: 1)
    let n = read(fileno(stdin), &buf, 1)
    if n <= 0 {
        exit(0)
    }
}
stdinSource.resume()

let ppidTimer = DispatchSource.makeTimerSource(queue: DispatchQueue.global(qos: .background))
ppidTimer.schedule(deadline: .now() + 1.0, repeating: 1.0)
ppidTimer.setEventHandler {
    if getppid() == 1 {
        exit(0)
    }
}
ppidTimer.resume()

dispatchMain()
