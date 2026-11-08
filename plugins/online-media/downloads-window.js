const t = (key, args) => iina.strings.get(key, args); // the plugin's messages (locales/), in the app's language
// IINA's plugin-online-media downloads-window.js, its mustache template written out.
function init() {
  iina.postMessage("requestUpdate", { force: true });

  let interval = null;
  const startUpdate = () => {
    if (interval === null) interval = setInterval(() => iina.postMessage("requestUpdate", { force: false }), 1000);
  };
  const stopUpdate = () => { clearInterval(interval); interval = null; };

  iina.onMessage("update", function (message) {
    message.active ? startUpdate() : stopUpdate();
    document.getElementById("content").innerHTML = render(message.data);
  });

  const activeContainer = () => Array.prototype.find.call(document.querySelectorAll(".download-container"), (el) => el.style.display === "block");

  iina.onMessage("updatingBinary", () => {
    const c = activeContainer();
    if (!c) { console.error("No active download container found for updatingBinary message"); return; }
    c.querySelector(".download-error").textContent = "";
    c.querySelector(".download-info").textContent = "";
    c.querySelector(".downloading").style.display = "block";
  });

  iina.onMessage("downloadingDeno", () => {
    const c = document.querySelector("#js-runtime-alert .download-container");
    c.querySelector(".download-error").textContent = "";
    c.querySelector(".download-info").textContent = "";
    c.querySelector(".downloading").style.display = "block";
  });

  iina.onMessage("binaryUpdated", ({ updated, error }) => {
    const c = activeContainer();
    if (!c) { console.error("No active download container found for binaryUpdated message"); return; }
    c.querySelector(".downloading").style.display = "none";
    if (updated) {
      c.querySelector(".download-info").textContent = t("Binary updated successfully. Please allow a few seconds preparing and verifying the new binary.");
      verifyBinaryInfo();
    } else {
      c.querySelector(".download-error").textContent = t("Failed to update binary: {error}", { error });
    }
  });

  iina.onMessage("denoDownloaded", ({ downloaded, error }) => {
    const c = document.querySelector("#js-runtime-alert .download-container");
    c.querySelector(".downloading").style.display = "none";
    if (downloaded) {
      c.querySelector(".download-info").textContent = t("Deno downloaded successfully.");
      setTimeout(verifyBinaryInfo, 2000);
    } else {
      c.querySelector(".download-error").textContent = t("Failed to download Deno: {error}", { error });
    }
  });

  window.openFile = (file) => iina.postMessage("openFile", { file: b64_to_utf8(file) });
  window.revealFile = (file) => iina.postMessage("revealFile", { fileName: b64_to_utf8(file) });

  document.getElementById("check-binary").addEventListener("click", verifyBinaryInfo);
  document.getElementById("download-binary").addEventListener("click", () => {
    document.querySelector(".status-bundled .download-container").style.display = "block";
    iina.postMessage("downloadBinary");
  });
  document.getElementById("update-managed-binary").addEventListener("click", () => {
    document.querySelector(".status-managed .download-container").style.display = "block";
    iina.postMessage("updateManagedBinary");
  });
  document.getElementById("show-binary-in-finder").addEventListener("click", () => iina.postMessage("showBinaryInFinder"));
  document.getElementById("download-deno").addEventListener("click", () => {
    document.querySelector("#js-runtime-alert .download-container").style.display = "block";
    iina.postMessage("downloadDeno");
  });

  iina.onMessage("binaryPath", updateBinaryStatus);
  iina.postMessage("getBinaryPath");
}

document.addEventListener("DOMContentLoaded", init);

const utf8_to_b64 = (str) => window.btoa(unescape(encodeURIComponent(str)));
const b64_to_utf8 = (str) => decodeURIComponent(escape(window.atob(str)));
const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);

function render(data) {
  if (!data || data.length === 0) {
    return '<div class="downloads-window"><div class="no-task">No downloads. If you just started a download, it may take a few seconds to show up here.</div></div>';
  }
  return '<div class="downloads-window">' + data.map((item) => {
    const progress = { pending: esc(t("Pending")), downloading: esc(t("{downloaded}/{total} (ETA {eta})", { downloaded: item.dl, total: item.total, eta: item.eta })), done: esc(t("Done")), error: esc(t("Error: {error}", { error: item.error })) }[item.status] ?? "";
    const dest = utf8_to_b64(item.dest);
    const options = item.status === "done" ? `<a href="#" onclick="openFile('${dest}')">${esc(t("Open"))}</a> <a href="#" onclick="revealFile('${dest}')">${esc(t("Reveal in Finder"))}</a>` : "";
    return `<div class="download-item"><div class="filename">${esc(item.filename)}</div><div class="progress"><div class="left">${progress}</div><div class="right task-options">${options}</div></div></div>`;
  }).join("") + "</div>";
}

function updateBinaryStatus({ path, jsRuntime }) {
  let statusClassName;
  if (!path || path === "youtube-dl") {
    statusClassName = "bundled";
  } else if (path === "@data/yt-dlp/yt-dlp_macos") {
    statusClassName = "managed";
  } else {
    statusClassName = "custom";
  }
  document.querySelectorAll(".binary-status").forEach((el) => { el.style.display = "none"; });
  const el = document.querySelector(`.binary-status.status-${statusClassName}`);
  if (el) el.style.display = "block";
  // We assume a custom binary (almost always installed by a package manager) has built-in JS runtime support
  document.getElementById("js-runtime-alert").style.display = statusClassName !== "custom" && !jsRuntime ? "block" : "none";
}

function verifyBinaryInfo() {
  document.getElementById("binary-desc").textContent = t("Checking for yt-dlp binary...");
  iina.onMessage("binaryInfo", ({ path, version, jsRuntime, errorMessage }) => {
    let description, binaryLocation = "";
    if (path === "youtube-dl") {
      description = t("No yt-dlp was found; youtube-dl is looked for on your system. It is recommended to download the latest yt-dlp using the button below.");
    } else if (path === "@data/yt-dlp/yt-dlp_macos") {
      description = t("You are using the yt-dlp installation managed by this plugin. The binary is in the plugin's data folder (Show in Finder).");
    } else {
      binaryLocation = path;
      description = t("It seems that you are using a custom yt-dlp binary. You may need to update it manually.");
      document.getElementById("download-binary").style.display = "none";
    }
    if (errorMessage) {
      document.getElementById("binary-version").textContent = errorMessage;
    } else {
      document.getElementById("binary-version").innerHTML = esc(t("Version: {version}", { version })) + (binaryLocation ? "<br>" + esc(t("Binary location: {path}", { path: binaryLocation })) : "");
    }
    document.getElementById("binary-desc").textContent = description;
    updateBinaryStatus({ path, jsRuntime });
  });
  iina.postMessage("getBinaryInfo");
}
