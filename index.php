<?php
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>GBEYAN OCR</title>
<style>
body{font-family:Arial,sans-serif;max-width:1050px;margin:30px auto;padding:0 16px;background:#f8fafc;color:#0f172a}
h1{margin-bottom:6px}.sub{color:#64748b;margin-bottom:18px}.box{background:#fff;border:1px solid #cbd5e1;border-radius:10px;padding:16px;margin-top:16px}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:15px}button{padding:11px 18px;font-size:14px;cursor:pointer;border:0;border-radius:7px}
button:disabled{opacity:.4;cursor:default}.primary{background:#2563eb;color:#fff}.excel{background:#15803d;color:#fff}.delete{background:#dc2626;color:#fff;padding:6px 9px}
#preview{display:none;max-width:100%;max-height:480px;margin-top:15px;border:1px solid #ddd;border-radius:8px}
#status{margin-top:15px;font-weight:bold;white-space:pre-wrap}.runtime{background:#e2e8f0;padding:10px;border-radius:7px;line-height:1.7}
.good{color:#15803d}.error{color:#dc2626}table{width:100%;border-collapse:collapse;margin-top:12px}th,td{padding:8px;border-bottom:1px solid #e2e8f0;text-align:left}
th{background:#f1f5f9}.crew-input,.crew-select{width:100%;box-sizing:border-box;padding:7px}
pre{background:#111827;color:#e5e7eb;padding:15px;border-radius:8px;white-space:pre-wrap;overflow-x:auto}.small{color:#64748b;font-size:12px}
</style>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
</head>
<body>

<h1>GBEYAN OCR</h1>
<div class="sub">PP-OCRv6 Medium / WebGPU / THY GenDec parser</div>

<div id="runtime" class="runtime">Sistem kontrol ediliyor...</div>

<div class="box">
  <input id="file" type="file" accept="image/*">
  <div class="actions">
    <button id="ocrButton" class="primary" disabled>GPU ile OCR Yap</button>
    <button id="excelButton" class="excel" disabled>Excel İndir</button>
  </div>
  <div id="status">Fotoğraf seç.</div>
  <img id="preview">
</div>

<div class="box">
  <strong>Performans</strong>
  <div id="metrics">Henüz sonuç yok.</div>
</div>

<div class="box">
  <strong>Tespit Edilen THY Ekibi</strong>
  <div class="small">Excel indirmeden önce isimleri veya tipi değiştirebilirsin.</div>
  <table>
    <thead>
      <tr>
        <th>Adı</th>
        <th>Soyadı</th>
        <th>THY Ham Tip</th>
        <th>GBEYAN Tipi</th>
        <th>Güven</th>
        <th></th>
      </tr>
    </thead>
    <tbody id="crewBody">
      <tr><td colspan="6">Henüz ekip bulunmadı.</td></tr>
    </tbody>
  </table>
</div>

<div class="box">
  <strong>Ekip JSON</strong>
  <pre id="crewJson">[]</pre>
</div>

<div class="box">
  <strong>Tam OCR Sonucu</strong>
  <pre id="ocrResult">Henüz sonuç yok.</pre>
</div>

<script type="module">
import { PaddleOCR } from "https://cdn.jsdelivr.net/npm/@paddleocr/paddleocr-js@0.4.2/+esm";

// PaddleOCR package normally tries to spawn its worker from jsDelivr.
// Redirect that worker to our own origin so the browser allows it.
const NativeWorker = window.Worker;
window.Worker = function(scriptURL, options) {
  const original = String(scriptURL);
  if (
    original.includes("@paddleocr/paddleocr-js@0.4.2") &&
    original.includes("worker-entry-")
  ) {
    scriptURL = location.origin + "/paddle-worker.js";
  }
  return new NativeWorker(scriptURL, options);
};
window.Worker.prototype = NativeWorker.prototype;

const fileInput = document.getElementById("file");
const ocrButton = document.getElementById("ocrButton");
const excelButton = document.getElementById("excelButton");
const previewEl = document.getElementById("preview");
const statusEl = document.getElementById("status");
const metricsEl = document.getElementById("metrics");
const crewBody = document.getElementById("crewBody");
const crewJsonEl = document.getElementById("crewJson");
const ocrResultEl = document.getElementById("ocrResult");
const runtimeEl = document.getElementById("runtime");

let selectedFile = null;
let engine = null;
let crew = [];

const hasWebGPU = typeof navigator.gpu !== "undefined";

runtimeEl.innerHTML =
  "WebGPU: <b>" + (hasWebGPU ? "VAR ✓" : "YOK") + "</b>" +
  "<br>Model: <b>PP-OCRv6 Medium</b>" +
  "<br>Backend: <b>WebGPU</b>" +
  "<br>Parser: <b>THY</b>" +
  "<br>Tipler: <b>C/C → CP &nbsp; C/P → FO &nbsp; S/A,S/S → CA</b>";

if (!hasWebGPU) {
  runtimeEl.innerHTML += "<br><b style='color:red'>Bu tarayıcı WebGPU desteklemiyor.</b>";
}

fileInput.addEventListener("change", () => {
  selectedFile = fileInput.files?.[0] || null;
  crew = [];
  renderCrew();
  excelButton.disabled = true;

  if (!selectedFile) {
    ocrButton.disabled = true;
    statusEl.textContent = "Fotoğraf seç.";
    return;
  }

  previewEl.src = URL.createObjectURL(selectedFile);
  previewEl.style.display = "block";
  ocrButton.disabled = !hasWebGPU;
  statusEl.className = "";
  statusEl.textContent = "Fotoğraf hazır: " + selectedFile.name;
});

async function getEngine() {
  if (engine) return engine;

  statusEl.textContent = "PP-OCRv6 Medium hazırlanıyor...";

  engine = await PaddleOCR.create({
    worker: true,

    textDetectionModelName: "PP-OCRv6_medium_det",
    textDetectionModelAsset: {
      url: location.origin + "/models/det.tar"
    },

    textRecognitionModelName: "PP-OCRv6_medium_rec",
    textRecognitionModelAsset: {
      url: location.origin + "/models/rec.tar"
    },

    textDetectionBatchSize: 1,
    textRecognitionBatchSize: 8,

    ortOptions: {
      backend: "webgpu",
      wasmPaths: "https://cdn.jsdelivr.net/npm/onnxruntime-web/dist/",
      simd: true
    }
  });

  return engine;
}

function findTHYRole(text) {
  const original = String(text || "").trim();

  const normalized = original
    .toUpperCase()
    .replace(/\\/g, "/")
    .replace(/\|/g, "/");

  const match = normalized.match(
    /(?:^|\s)(C\s*\/\s*C|C\s*\/\s*P|S\s*\/\s*A|S\s*\/\s*S|CC|CP|SA|SS)(?=\s|$)/
  );

  if (!match) return null;

  const token = match[1].replace(/\s/g, "");

  let type = null;

  if (token === "C/C" || token === "CC") type = "CP";
  else if (token === "C/P" || token === "CP") type = "FO";
  else if (
    token === "S/A" ||
    token === "SA" ||
    token === "S/S" ||
    token === "SS"
  ) type = "CA";

  if (!type) return null;

  return {
    type,
    rawType: token
  };
}

function cleanTHYName(text) {
  let t = String(text || "")
    .trim()
    .replace(/\s+/g, " ");

  const words = t.split(" ");

  if (
    words.length >= 3 &&
    /^[A-Z]{3}$/.test(words[0])
  ) {
    words.shift();
    t = words.join(" ");
  }

  t = t.replace(/\s+TUR$/i, "");

  return t.trim();
}

function parseTHYName(text) {
  const t = cleanTHYName(text);

  const parts = t
    .split(/\s+/)
    .filter(Boolean);

  if (parts.length < 2) return null;

  return {
    firstName: parts.slice(0, parts.length - 1).join(" "),
    lastName: parts[parts.length - 1]
  };
}

// THY OCR order is normally:
// NAME
// C/C, C/P, S/A, S/S
// TUR
//
// We therefore pair each role token with the nearest valid name before it.
// This is more reliable than x/y coordinates on angled phone photos.
function extractTHYCrew(items) {
  const result = [];

  const rows = items.map((item, index) => ({
    index,
    text: String(item.text || "").trim(),
    score: typeof item.score === "number" ? item.score : null
  }));

  function isTHYNameCandidate(text) {
    const t = String(text || "")
      .trim()
      .replace(/\s+/g, " ");

    if (!t) return false;
    if (findTHYRole(t)) return false;

    if (/^(TUR|AYT|RMO|VKO)$/i.test(t)) return false;
    if (/^[:\-]?\d/.test(t)) return false;

    if (
      /GENERAL|DECLARATION|ICAO|OWNER|OPERATOR|MARKS|REGISTRATION|FLIGHT|ROUTING|TOTAL|NUMBER|CREW|PLACE|DATE|BIRTH|NATIONALITY|SIGNATURE|PASSENGER|STAGE|DEPARTURE|ARRIVAL|EMBARKING|DISEMBARKING|THROUGH|OFFICIAL|HEALTH|PERSONS|ILLNESS|STATE|REQUIRED|COMPLETED|MANIFEST|DESTINATION|PILOT|DOCUMENT|BOARD|FUEL/i.test(t)
    ) {
      return false;
    }

    const words = t.split(/\s+/);

    if (words.length < 2 || words.length > 4) return false;

    return /^[A-ZÇĞİÖŞÜÀ-Ž'. -]+$/i.test(t);
  }

  for (let i = 0; i < rows.length; i++) {
    const current = rows[i];
    const role = findTHYRole(current.text);

    if (!role) continue;

    let nameRow = null;

    // Search backwards through a few OCR boxes because labels such as
    // DEPARTURE PLACE can appear between a name and its crew code.
    for (let back = 1; back <= 6; back++) {
      const candidate = rows[i - back];
      if (!candidate) break;

      if (isTHYNameCandidate(candidate.text)) {
        nameRow = candidate;
        break;
      }
    }

    if (!nameRow) continue;

    const person = parseTHYName(nameRow.text);
    if (!person) continue;

    result.push({
      firstName: person.firstName,
      lastName: person.lastName,
      rawType: role.rawType,
      type: role.type,
      confidence: current.score,
      nameConfidence: nameRow.score,
      nameSource: nameRow.text,
      roleSource: current.text
    });
  }

  const seen = new Set();

  return result.filter(person => {
    const key = (
      person.firstName + "|" +
      person.lastName + "|" +
      person.type
    ).toUpperCase();

    if (seen.has(key)) return false;
    seen.add(key);
    return true;
  });
}

function renderCrew() {
  crewBody.innerHTML = "";

  if (!crew.length) {
    crewBody.innerHTML =
      '<tr><td colspan="6">Henüz ekip bulunmadı.</td></tr>';

    crewJsonEl.textContent = "[]";
    excelButton.disabled = true;
    return;
  }

  crew.forEach((person, index) => {
    const tr = document.createElement("tr");

    const firstTd = document.createElement("td");
    const first = document.createElement("input");
    first.className = "crew-input";
    first.value = person.firstName;
    first.addEventListener("input", e => {
      crew[index].firstName = e.target.value;
      updateJson();
    });
    firstTd.appendChild(first);

    const lastTd = document.createElement("td");
    const last = document.createElement("input");
    last.className = "crew-input";
    last.value = person.lastName;
    last.addEventListener("input", e => {
      crew[index].lastName = e.target.value;
      updateJson();
    });
    lastTd.appendChild(last);

    const rawTd = document.createElement("td");
    rawTd.textContent = person.rawType;

    const typeTd = document.createElement("td");
    const select = document.createElement("select");
    select.className = "crew-select";

    ["CP", "FO", "CA"].forEach(type => {
      const opt = document.createElement("option");
      opt.value = type;
      opt.textContent = type;
      opt.selected = type === person.type;
      select.appendChild(opt);
    });

    select.addEventListener("change", e => {
      crew[index].type = e.target.value;
      updateJson();
    });

    typeTd.appendChild(select);

    const confidenceTd = document.createElement("td");
    confidenceTd.textContent =
      typeof person.confidence === "number"
        ? (person.confidence * 100).toFixed(1) + "%"
        : "-";

    const actionTd = document.createElement("td");
    const del = document.createElement("button");
    del.className = "delete";
    del.textContent = "Sil";
    del.addEventListener("click", () => {
      crew.splice(index, 1);
      renderCrew();
    });
    actionTd.appendChild(del);

    tr.appendChild(firstTd);
    tr.appendChild(lastTd);
    tr.appendChild(rawTd);
    tr.appendChild(typeTd);
    tr.appendChild(confidenceTd);
    tr.appendChild(actionTd);

    crewBody.appendChild(tr);
  });

  excelButton.disabled = false;
  updateJson();
}

function updateJson() {
  crewJsonEl.textContent = JSON.stringify(
    crew.map(x => ({
      firstName: x.firstName,
      lastName: x.lastName,
      rawType: x.rawType,
      crewType: x.type
    })),
    null,
    2
  );
}

function excelText(value) {
  let s = String(value || "");

  const map = {
    "ı": "i", "İ": "I",
    "ş": "s", "Ş": "S",
    "ğ": "g", "Ğ": "G",
    "ç": "c", "Ç": "C",
    "ö": "o", "Ö": "O",
    "ü": "u", "Ü": "U"
  };

  s = s.replace(
    /[ıİşŞğĞçÇöÖüÜ]/g,
    c => map[c] || c
  );

  s = s
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "");

  return s.toUpperCase().trim();
}

function downloadExcel() {
  if (!crew.length) return;

  if (typeof XLSX === "undefined") {
    alert("Excel kütüphanesi yüklenemedi.");
    return;
  }

  const rows = [[
    "Adı",
    "Soyadı",
    "Doğum Tarihi",
    "Milliyeti (REF)",
    "Mürettebat Tipi (REF)",
    "Belge Tipi (REF)",
    "Belge No"
  ]];

  for (const person of crew) {
    rows.push([
      excelText(person.firstName),
      excelText(person.lastName),
      "",
      "",
      person.type,
      "",
      ""
    ]);
  }

  const ws = XLSX.utils.aoa_to_sheet(rows);

  ws["!cols"] = [
    { wch: 20 },
    { wch: 20 },
    { wch: 16 },
    { wch: 18 },
    { wch: 24 },
    { wch: 20 },
    { wch: 20 }
  ];

  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, "Worksheet");
  XLSX.writeFile(wb, "ek.xlsx");
}

async function runOCR() {
  if (!selectedFile) return;

  ocrButton.disabled = true;
  excelButton.disabled = true;
  crew = [];
  renderCrew();

  statusEl.className = "";
  statusEl.textContent = "THY GenDec WebGPU ile okunuyor...";
  metricsEl.textContent = "Bekleniyor...";
  ocrResultEl.textContent = "OCR hazırlanıyor...";

  try {
    const ocr = await getEngine();

    const start = performance.now();

    const results = await ocr.predict(
      selectedFile,
      {
        textRecScoreThresh: 0,
        textDetLimitSideLen: 960,
        textDetLimitType: "max"
      }
    );

    const elapsed = performance.now() - start;
    const output = results?.[0];

    if (!output) {
      throw new Error("OCR sonucu boş.");
    }

    const items = output.items || [];
    const metrics = output.metrics || {};

    ocrResultEl.textContent = items.map((item, i) => {
      const confidence =
        typeof item.score === "number"
          ? (item.score * 100).toFixed(1)
          : "-";

      return (
        (i + 1) +
        ". [" + confidence + "%] " +
        item.text
      );
    }).join("\n");

    crew = extractTHYCrew(items);
    renderCrew();

    metricsEl.innerHTML =
      "Backend: <b>WebGPU</b>" +
      "<br>Toplam: <b>" + elapsed.toFixed(0) + " ms</b>" +
      "<br>DET: <b>" + Number(metrics.detMs || 0).toFixed(0) + " ms</b>" +
      "<br>REC: <b>" + Number(metrics.recMs || 0).toFixed(0) + " ms</b>" +
      "<br>OCR satırı: <b>" + items.length + "</b>" +
      "<br>THY ekip: <b>" + crew.length + "</b>";

    statusEl.className = "good";
    statusEl.textContent =
      "BİTTİ — " +
      crew.length +
      " ekip üyesi bulundu.";

  } catch (error) {
    console.error(error);

    statusEl.className = "error";
    statusEl.textContent = "HATA";

    ocrResultEl.textContent =
      error?.stack ||
      error?.message ||
      String(error);
  } finally {
    ocrButton.disabled =
      !selectedFile ||
      !hasWebGPU;
  }
}

ocrButton.addEventListener("click", runOCR);
excelButton.addEventListener("click", downloadExcel);
</script>
</body>
</html>
