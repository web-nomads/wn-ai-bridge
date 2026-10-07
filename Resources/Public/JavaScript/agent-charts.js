import '@webnomads/wn-ai-bridge/Contrib/chart.umd.min.js';

/**
 * Agent Analytics: line charts from data-wn-ai-chart, redrawn after every AJAX filter request;
 * the period field shows the custom dates
 */
const Chart = globalThis.Chart;
const charts = new Set();

// text colour of the surrounding card, normalised to rgb by the canvas API
function theme(element) {
  const context = document.createElement('canvas').getContext('2d');
  context.fillStyle = '#6b7280';
  context.fillStyle = getComputedStyle(element.closest('.card') ?? document.body).color;
  const value = context.fillStyle;
  const numbers = (value.match(/[\d.]+/g) ?? ['107', '114', '128']).slice(0, 3).map(Number);
  let rgb;
  if (value.startsWith('#')) {
    rgb = [1, 3, 5].map((i) => parseInt(value.slice(i, i + 2), 16));
  } else if (value.startsWith('color(')) {
    // color(srgb r g b) uses 0..1 channels
    rgb = numbers.map((n) => Math.round(n * 255));
  } else {
    rgb = numbers;
  }
  rgb = rgb.join(', ');
  return { text: `rgba(${rgb}, 0.8)`, grid: `rgba(${rgb}, 0.14)` };
}

function render(canvas) {
  let data;
  try {
    data = JSON.parse(canvas.dataset.wnAiChart);
  } catch {
    return;
  }
  const colors = theme(canvas);
  charts.add(new Chart(canvas, {
    type: 'line',
    data: {
      labels: data.labels,
      datasets: data.series.map((series) => ({
        label: series.label,
        data: series.values,
        borderColor: series.color,
        backgroundColor: series.color,
        borderWidth: 2,
        pointRadius: 2.5,
        // counts: straight lines, no curves between days
        tension: 0,
      })),
    },
    options: {
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      scales: {
        y: { beginAtZero: true, ticks: { color: colors.text, precision: 0 }, grid: { color: colors.grid } },
        x: { ticks: { color: colors.text }, grid: { color: colors.grid } },
      },
      plugins: {
        legend: { position: 'bottom', labels: { color: colors.text, usePointStyle: true, boxWidth: 8, boxHeight: 8, padding: 14 } },
      },
    },
  }));
}

function renderAll() {
  if (!Chart) {
    return;
  }
  // charts of replaced results
  for (const chart of charts) {
    if (!chart.canvas.isConnected) {
      chart.destroy();
      charts.delete(chart);
    }
  }
  document.querySelectorAll('canvas[data-wn-ai-chart]').forEach((canvas) => {
    if (!Chart.getChart(canvas)) {
      render(canvas);
    }
  });
}

function toggleDates() {
  document.querySelectorAll('[data-wn-ai-agents-period]').forEach((select) => {
    select.form.querySelectorAll('[data-wn-ai-agents-dates]').forEach((field) => {
      field.hidden = select.value !== 'custom';
    });
  });
}

renderAll();
document.querySelectorAll('[data-wn-ai-agents-period]').forEach((select) => select.addEventListener('change', toggleDates));
document.addEventListener('wn-ai-filter:loaded', () => {
  toggleDates();
  renderAll();
});
