<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use RuntimeException;

/**
 * The text in a picture, as Vision finds it (VNRecognizeTextRequest, its
 * accurate level, the language found of itself, as VisionKit's Live Text
 * that IINA uses): found off the main thread (the request performed by an
 * NSInvocationOperation on a queue of its own, no PHP in it), its words
 * read once it is done, each with its box, of the picture's size (0 to 1,
 * from its top left), its line's number among them.
 *
 * @internal UI\MoviePlayerLiveText
 */
final class TextRecognizer
{
    private $request, $handler, $operation, $queue;
    /** The arguments' storage, kept while the invocation holds pointers to it. */
    private $args;

    /** Starts finding the text in the image $png (its PNG or JPEG bytes). */
    public function __construct(string $png)
    {
        ObjC::boot(['Foundation', 'Vision']);
        $data = ObjC::msg('p:sL', ObjC::cls('NSData'), 'dataWithBytes:length:', $png, strlen($png));
        $this->handler = ObjC::msg('p:pp', ObjC::alloc('VNImageRequestHandler'), 'initWithData:options:', $data, ObjC::msg('p:', ObjC::cls('NSDictionary'), 'dictionary'));
        $this->request = ObjC::msg('p:', ObjC::alloc('VNRecognizeTextRequest'), 'init');
        if ($this->handler === null || $this->request === null) { throw new RuntimeException('myphp: Vision finds no text here'); }
        ObjC::msg('v:l', $this->request, 'setRecognitionLevel:', 0); // VNRequestTextRecognitionLevelAccurate
        ObjC::msg('v:b', $this->request, 'setUsesLanguageCorrection:', true);
        ObjC::msg('v:b', $this->request, 'setAutomaticallyDetectsLanguage:', true);
        $requests = ObjC::msg('p:p', ObjC::cls('NSArray'), 'arrayWithObject:', $this->request);
        $sel = ObjC::sel('performRequests:error:');
        $sig = ObjC::msg('p:p', $this->handler, 'methodSignatureForSelector:', $sel);
        $inv = ObjC::msg('p:p', ObjC::cls('NSInvocation'), 'invocationWithMethodSignature:', $sig);
        ObjC::msg('v:p', $inv, 'setTarget:', $this->handler);
        ObjC::msg('v:p', $inv, 'setSelector:', $sel);
        $this->args = ObjC::$c->new('void*[2]');
        $this->args[0] = $requests;
        $this->args[1] = null; // no NSError**
        ObjC::msg('v:pl', $inv, 'setArgument:atIndex:', ObjC::$c->cast('void*', FFI::addr($this->args[0])), 2);
        ObjC::msg('v:pl', $inv, 'setArgument:atIndex:', ObjC::$c->cast('void*', FFI::addr($this->args[1])), 3);
        ObjC::msg('v:', $inv, 'retainArguments');
        $this->operation = ObjC::msg('p:p', ObjC::alloc('NSInvocationOperation'), 'initWithInvocation:', $inv);
        $this->queue = ObjC::msg('p:', ObjC::alloc('NSOperationQueue'), 'init');
        ObjC::msg('v:p', $this->queue, 'addOperation:', $this->operation);
    }

    public function __destruct()
    {
        $this->cancel();
        foreach ([$this->operation, $this->queue, $this->request, $this->handler] as $o) { ObjC::msg('v:', $o, 'release'); } // those alloc'd here
    }

    /** Whether Vision is done. */
    public function done(): bool
    {
        return ObjC::msg('b:', $this->operation, 'isFinished');
    }

    /** Stops it, where it has not begun. */
    public function cancel(): void
    {
        ObjC::msg('v:', $this->operation, 'cancel');
    }

    /**
     * The words found, once done: their text, box (x, y from the top left,
     * w, h, of the picture's size) and line, in the order they read.
     * @return list<array{text: string, x: float, y: float, w: float, h: float, line: int, space: bool}> (space: a space after it)
     */
    public function words(): array
    {
        $out = [];
        $results = ObjC::msg('p:', $this->request, 'results');
        for ($i = 0, $n = $results === null ? 0 : ObjC::msg('L:', $results, 'count'); $i < $n; $i++) {
            $obs = ObjC::msg('p:L', $results, 'objectAtIndex:', $i);
            $cands = ObjC::msg('p:L', $obs, 'topCandidates:', 1);
            if ($cands === null || ObjC::msg('L:', $cands, 'count') === 0) { continue; }
            $text = ObjC::msg('p:L', $cands, 'objectAtIndex:', 0);
            $string = ObjC::phpStr(ObjC::msg('p:', $text, 'string'));
            $line = ObjC::msg('r:', $obs, 'boundingBox');
            // its words, each its own box (boundingBoxForRange:error:, in UTF-16 units); a line of no spaces (Chinese) one word of each character
            $u16 = 0;
            foreach (preg_split('/(\s+)/u', $string, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $piece) {
                $len = intdiv(strlen(mb_convert_encoding($piece, 'UTF-16LE', 'UTF-8')), 2);
                if ($piece !== '' && trim($piece) !== '') {
                    $chars = preg_match('/\p{Han}|\p{Hiragana}|\p{Katakana}|\p{Hangul}/u', $piece) === 1 ? mb_str_split($piece) : [$piece];
                    $at = $u16;
                    foreach ($chars as $ch) {
                        $l = intdiv(strlen(mb_convert_encoding($ch, 'UTF-16LE', 'UTF-8')), 2);
                        $box = ObjC::msg('p:np', $text, 'boundingBoxForRange:error:', ObjC::range($at, $l), null);
                        $r = $box === null ? $line : ObjC::msg('r:', $box, 'boundingBox');
                        $out[] = ['text' => $ch, 'x' => $r->x, 'y' => 1 - $r->y - $r->h, 'w' => $r->w, 'h' => $r->h, 'line' => $i, 'space' => false];
                        $at += $l;
                    }
                } elseif ($out !== []) {
                    $out[count($out) - 1]['space'] = true; // what follows it a space
                }
                $u16 += $len;
            }
        }
        return $out;
    }
}
