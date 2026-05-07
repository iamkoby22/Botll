(function () {
  const metaEl = document.getElementById("tiliaMeta");
  const overlay = document.getElementById("tiliaOverlay");
  const panel = document.getElementById("tiliaPanel");
  const btnClose = document.getElementById("tiliaClose");
  const btnSend = document.getElementById("tiliaSend");
  const input = document.getElementById("tiliaInput");
  const messages = document.getElementById("tiliaMessages");
  const starters = document.getElementById("tiliaStarters");

  let meta = { csrf: "", endpoint: "/api/tilia_assistant.php", webBase: "" };
  try {
    if (metaEl && metaEl.textContent) {
      const raw = metaEl.textContent.trim();
      meta = Object.assign(meta, JSON.parse(raw));
    }
  } catch (_) {
    /* ignore */
  }

  /** Root-relative URLs work from every page depth (unlike bare "api/..."). */
  function tiliaAssistantUrl(endpoint) {
    let ep = String(endpoint || "").trim();
    if (!ep) ep = "/api/tilia_assistant.php";
    if (/^https?:\/\//i.test(ep)) return ep;
    if (ep.startsWith("/")) {
      try {
        return new URL(ep, window.location.origin).href;
      } catch (_) {
        return ep;
      }
    }
    try {
      return new URL("/" + ep.replace(/^\.?\//, ""), window.location.origin).href;
    } catch (_) {
      return meta.webBase ? new URL(meta.webBase.replace(/\/$/, "") + "/" + ep, window.location.origin).href : "/" + ep;
    }
  }

  function openTilia() {
    if (!overlay || !panel) return;
    overlay.hidden = false;
    overlay.classList.add("show");
    panel.hidden = false;
    panel.classList.add("show");
    panel.setAttribute("aria-hidden", "false");
    if (input) input.focus();
  }

  function closeTilia() {
    if (!overlay || !panel) return;
    overlay.classList.remove("show");
    overlay.hidden = true;
    panel.classList.remove("show");
    panel.hidden = true;
    panel.setAttribute("aria-hidden", "true");
  }

  function appendBubble(text, who) {
    if (!messages) return;
    const row = document.createElement("div");
    row.className = "d-flex " + (who === "user" ? "justify-content-end" : "justify-content-start");
    const div = document.createElement("div");
    div.className = "chat-bubble " + (who === "user" ? "user" : "bot");
    div.textContent = text;
    row.appendChild(div);
    messages.appendChild(row);
    messages.scrollTop = messages.scrollHeight;
  }

  async function sendQuestion(q) {
    const question = (q || "").trim();
    if (!question) return;
    appendBubble(question, "user");
    const thinkingId = "tiliaThinking-" + String(Date.now());
    if (messages) {
      const row = document.createElement("div");
      row.className = "d-flex justify-content-start";
      row.id = thinkingId;
      const div = document.createElement("div");
      div.className = "chat-bubble bot";
      div.textContent = "Thinking...";
      row.appendChild(div);
      messages.appendChild(row);
      messages.scrollTop = messages.scrollHeight;
    }
    const url = tiliaAssistantUrl(meta.endpoint);
    try {
      const res = await fetch(url, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ question: question, csrf: meta.csrf || "" }),
      });
      const text = await res.text();
      let data = null;
      try {
        data = JSON.parse(text);
      } catch (_) {
        data = null;
      }
      const thinking = document.getElementById(thinkingId);
      if (thinking && thinking.parentNode) thinking.parentNode.removeChild(thinking);
      if (!data || typeof data !== "object") {
        appendBubble(
          "Couldn't reach assistant. Try again.",
          "bot"
        );
        if (typeof console !== "undefined" && console.debug) {
          console.debug("[Tilia] Non-JSON response", res.status, text.slice(0, 240));
        }
        return;
      }
      const answer = typeof data.answer === "string" ? data.answer : "";
      if (!res.ok && !answer) {
        appendBubble("Couldn't reach assistant. Try again.", "bot");
        return;
      }
      appendBubble(answer || "Sorry, something went wrong.", "bot");
    } catch (e) {
      const thinking = document.getElementById(thinkingId);
      if (thinking && thinking.parentNode) thinking.parentNode.removeChild(thinking);
      appendBubble("Couldn't reach assistant. Try again.", "bot");
      if (typeof console !== "undefined" && console.debug) {
        console.debug("[Tilia] fetch error", e);
      }
    }
  }

  document.querySelectorAll("[data-tilia-open]").forEach((el) => {
    el.addEventListener("click", (e) => {
      e.preventDefault();
      openTilia();
    });
  });
  if (btnClose) btnClose.addEventListener("click", closeTilia);
  if (overlay)
    overlay.addEventListener("click", () => {
      closeTilia();
    });
  if (btnSend)
    btnSend.addEventListener("click", () => {
      if (!input) return;
      const v = input.value;
      input.value = "";
      sendQuestion(v);
    });
  if (input)
    input.addEventListener("keydown", (e) => {
      if (e.key === "Enter") {
        e.preventDefault();
        btnSend && btnSend.click();
      }
    });
  if (starters) {
    starters.addEventListener("click", (e) => {
      const t = e.target;
      if (t && t.getAttribute && t.getAttribute("data-q")) {
        sendQuestion(t.getAttribute("data-q"));
      }
    });
  }

  /** Chart.js: fixed-height parents + destroy previous instances (prevents runaway canvas resize). */
  function initBotllCharts() {
    if (typeof Chart === "undefined") {
      return;
    }

    window.__BOTLL_CHART_REGISTRY__ = window.__BOTLL_CHART_REGISTRY__ || {};

    function destroyIfAny(canvasId) {
      const reg = window.__BOTLL_CHART_REGISTRY__;
      const existing = reg[canvasId];
      if (existing) {
        try {
          existing.destroy();
        } catch (_) {
          /* ignore */
        }
        delete reg[canvasId];
      }
    }

    function mountChart(canvasId, config) {
      const el = document.getElementById(canvasId);
      if (!el || !el.getContext) {
        return;
      }
      destroyIfAny(canvasId);
      const reg = window.__BOTLL_CHART_REGISTRY__;
      reg[canvasId] = new Chart(el, config);
    }
    const common = {
      responsive: true,
      maintainAspectRatio: false,
      resizeDelay: 0,
      plugins: { legend: { position: "bottom" } },
    };

    const d = window.__DASHBOARD__;
    if (d && d.deptLabels && d.deptLabels.length) {
      mountChart("chartDept", {
        type: "bar",
        data: {
          labels: d.deptLabels,
          datasets: [
            {
              label: "Tickets",
              data: d.deptValues,
              borderRadius: 8,
              backgroundColor: "rgba(227, 27, 141, 0.55)",
              borderColor: "rgba(227, 27, 141, 1)",
              borderWidth: 1,
            },
          ],
        },
        options: { ...common, scales: { x: { ticks: { maxRotation: 0 } }, y: { beginAtZero: true } } },
      });
    }

    if (d && d.priLabels && d.priLabels.length) {
      mountChart("chartPriority", {
        type: "doughnut",
        data: {
          labels: d.priLabels,
          datasets: [
            {
              data: d.priValues,
              backgroundColor: ["#7c3aed", "#e11d48", "#f97316", "#64748b"],
              borderWidth: 0,
            },
          ],
        },
        options: common,
      });
    }

    if (d && d.trendLabels && d.trendLabels.length) {
      mountChart("chartTrend", {
        type: "line",
        data: {
          labels: d.trendLabels,
          datasets: [
            {
              label: "Created",
              data: d.createdLine,
              borderColor: "#7c3aed",
              backgroundColor: "rgba(124, 58, 237, 0.15)",
              tension: 0.35,
              fill: true,
            },
            {
              label: "Resolved",
              data: d.resolvedLine,
              borderColor: "#e11d48",
              backgroundColor: "rgba(225, 29, 72, 0.10)",
              tension: 0.35,
              fill: true,
            },
          ],
        },
        options: { ...common, scales: { x: { ticks: { maxRotation: 0 } }, y: { beginAtZero: true } } },
      });
    }

    const r = window.__REPORTS__;
    if (r && r.deptLabels && r.deptLabels.length) {
      mountChart("repDept", {
        type: "bar",
        data: { labels: r.deptLabels, datasets: [{ label: "Tickets", data: r.deptValues, backgroundColor: "rgba(124,58,237,0.55)" }] },
        options: { ...common, scales: { y: { beginAtZero: true } } },
      });
    }
    if (r && r.priLabels && r.priLabels.length) {
      mountChart("repPri", {
        type: "doughnut",
        data: { labels: r.priLabels, datasets: [{ data: r.priValues, backgroundColor: ["#7c3aed", "#e11d48", "#f97316", "#64748b"] }] },
        options: common,
      });
    }
    if (r && r.statusLabels && r.statusLabels.length) {
      mountChart("repStatus", {
        type: "pie",
        data: { labels: r.statusLabels, datasets: [{ data: r.statusValues, backgroundColor: ["#22c55e", "#64748b", "#f97316", "#e11d48", "#a855f7"] }] },
        options: common,
      });
    }
    if (r && r.trendLabels && r.trendLabels.length) {
      mountChart("repTrend", {
        type: "line",
        data: {
          labels: r.trendLabels,
          datasets: [
            { label: "Created", data: r.createdLine, borderColor: "#7c3aed", tension: 0.35, fill: true, backgroundColor: "rgba(124,58,237,0.12)" },
            { label: "Completed", data: r.resolvedLine, borderColor: "#e11d48", tension: 0.35, fill: true, backgroundColor: "rgba(225,29,72,0.10)" },
          ],
        },
        options: { ...common, scales: { x: { ticks: { maxRotation: 0 } }, y: { beginAtZero: true } } },
      });
    }
    if (r && r.slaLabels && r.slaLabels.length) {
      mountChart("repSla", {
        type: "bar",
        data: { labels: r.slaLabels, datasets: [{ label: "SLA breaches", data: r.slaValues, backgroundColor: "rgba(225,29,72,0.55)" }] },
        options: { ...common, scales: { y: { beginAtZero: true } } },
      });
    }
  }

  function scheduleCharts() {
    if (!window.__DASHBOARD__ && !window.__REPORTS__) {
      return;
    }
    if (document.readyState === "complete") {
      initBotllCharts();
    } else {
      window.addEventListener(
        "load",
        function () {
          initBotllCharts();
        },
        { once: true }
      );
    }
  }

  scheduleCharts();
})();

