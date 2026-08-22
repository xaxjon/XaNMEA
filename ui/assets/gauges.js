/* XaNMEA gauges.js - hand-rolled canvas instruments, no dependencies.
   All drawing is devicePixelRatio-aware; call the returned update(value)
   whenever data changes. */

window.Gauges = (function () {
  'use strict';

  var GREEN = '#2ee884', AMBER = '#ffb02e', RED = '#ff5252',
      FG = '#cfe8da', DIM = '#6b8577', FAINT = '#3d5449', PANEL = '#0c1411';

  var MONO = 'ui-monospace, "SF Mono", "Cascadia Mono", Menlo, Consolas, monospace';

  // Size canvas backing store to its CSS box * dpr. Returns ctx or null.
  function fit(canvas) {
    var w = canvas.clientWidth, h = canvas.clientHeight;
    if (!w || !h) return null;
    var dpr = window.devicePixelRatio || 1;
    if (canvas.width !== Math.round(w * dpr) || canvas.height !== Math.round(h * dpr)) {
      canvas.width = Math.round(w * dpr);
      canvas.height = Math.round(h * dpr);
    }
    var ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, w, h);
    return { ctx: ctx, w: w, h: h };
  }

  function fmt(v, dec) {
    if (v === null || v === undefined || isNaN(v)) return '--';
    return Number(v).toFixed(dec === undefined ? 1 : dec);
  }

  /**
   * Arc dial: 270-degree sweep, colored value arc, big central numeral.
   * opts: {min, max, unit, decimals, label, arc (color), ticks}
   * Returns update(value) - pass null for "no data".
   */
  function dial(canvas, opts) {
    opts = opts || {};
    var min = opts.min !== undefined ? opts.min : 0;
    var max = opts.max !== undefined ? opts.max : 10;
    var dec = opts.decimals !== undefined ? opts.decimals : 1;
    var unit = opts.unit || '';
    var arcColor = opts.arc || GREEN;

    var A0 = Math.PI * 0.75;          // 135 deg
    var SWEEP = Math.PI * 1.5;        // 270 deg

    function draw(value) {
      var g = fit(canvas);
      if (!g) return;
      var ctx = g.ctx, w = g.w, h = g.h;
      var cx = w / 2, cy = h * 0.52;
      var r = Math.min(w / 2, h * 0.62) - 8;

      // track
      ctx.lineWidth = Math.max(4, r * 0.09);
      ctx.lineCap = 'round';
      ctx.strokeStyle = FAINT;
      ctx.beginPath();
      ctx.arc(cx, cy, r, A0, A0 + SWEEP);
      ctx.stroke();

      var frac = null;
      if (value !== null && !isNaN(value)) {
        frac = (value - min) / (max - min);
        frac = Math.max(0, Math.min(1, frac));
        ctx.strokeStyle = arcColor;
        ctx.shadowColor = arcColor;
        ctx.shadowBlur = 8;
        ctx.beginPath();
        ctx.arc(cx, cy, r, A0, A0 + SWEEP * frac);
        ctx.stroke();
        ctx.shadowBlur = 0;
      }

      // ticks
      ctx.strokeStyle = DIM;
      ctx.lineWidth = 1.5;
      var nTicks = 10;
      for (var i = 0; i <= nTicks; i++) {
        var a = A0 + SWEEP * (i / nTicks);
        var r1 = r - 6, r2 = r - 12;
        ctx.beginPath();
        ctx.moveTo(cx + r1 * Math.cos(a), cy + r1 * Math.sin(a));
        ctx.lineTo(cx + r2 * Math.cos(a), cy + r2 * Math.sin(a));
        ctx.stroke();
      }

      // numeral
      ctx.fillStyle = value === null ? FAINT : FG;
      ctx.font = '700 ' + Math.round(r * 0.55) + 'px ' + MONO;
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillText(value === null ? '--' : fmt(value, dec), cx, cy);

      if (unit) {
        ctx.fillStyle = DIM;
        ctx.font = Math.round(r * 0.18) + 'px ' + MONO;
        ctx.fillText(unit, cx, cy + r * 0.42);
      }
    }

    draw(null);
    return draw;
  }

  /**
   * Compass rose: heading-up rotating card, lubber line at top,
   * optional COG bug. Returns update({deg, bug}).
   */
  function compass(canvas, opts) {
    opts = opts || {};

    function draw(data) {
      var g = fit(canvas);
      if (!g) return;
      var ctx = g.ctx, w = g.w, h = g.h;
      var cx = w / 2, cy = h / 2;
      var r = Math.min(w, h) / 2 - 10;
      var deg = (data && data.deg !== null && data.deg !== undefined) ? data.deg : null;
      var bug = (data && data.bug !== null && data.bug !== undefined) ? data.bug : null;

      ctx.save();
      ctx.translate(cx, cy);

      // outer ring
      ctx.strokeStyle = FAINT;
      ctx.lineWidth = 2;
      ctx.beginPath();
      ctx.arc(0, 0, r, 0, Math.PI * 2);
      ctx.stroke();

      // card ticks, drawn in heading-up frame
      for (var t = 0; t < 360; t += 10) {
        var rel = (t - (deg || 0)) * Math.PI / 180;
        var mj = t % 30 === 0;
        ctx.strokeStyle = mj ? DIM : FAINT;
        ctx.lineWidth = mj ? 2 : 1;
        ctx.beginPath();
        ctx.moveTo(Math.sin(rel) * (r - 2), -Math.cos(rel) * (r - 2));
        ctx.lineTo(Math.sin(rel) * (r - (mj ? 14 : 8)), -Math.cos(rel) * (r - (mj ? 14 : 8)));
        ctx.stroke();
        if (t % 90 === 0) {
          var label = {0: 'N', 90: 'E', 180: 'S', 270: 'W'}[t];
          ctx.fillStyle = t === 0 ? RED : FG;
          ctx.font = '700 ' + Math.round(r * 0.22) + 'px ' + MONO;
          ctx.textAlign = 'center';
          ctx.textBaseline = 'middle';
          ctx.fillText(label, Math.sin(rel) * (r - 30), -Math.cos(rel) * (r - 30));
        } else if (t % 30 === 0) {
          ctx.fillStyle = DIM;
          ctx.font = Math.round(r * 0.13) + 'px ' + MONO;
          ctx.textAlign = 'center';
          ctx.textBaseline = 'middle';
          ctx.fillText(String(t / 10), Math.sin(rel) * (r - 28), -Math.cos(rel) * (r - 28));
        }
      }

      // COG bug
      if (bug !== null && deg !== null) {
        var ba = (bug - deg) * Math.PI / 180;
        var bx = Math.sin(ba) * (r - 8), by = -Math.cos(ba) * (r - 8);
        ctx.fillStyle = GREEN;
        ctx.beginPath();
        ctx.moveTo(bx, by - 7);
        ctx.lineTo(bx + 5, by);
        ctx.lineTo(bx, by + 7);
        ctx.lineTo(bx - 5, by);
        ctx.closePath();
        ctx.fill();
      }

      // lubber line
      ctx.fillStyle = AMBER;
      ctx.beginPath();
      ctx.moveTo(0, -r - 2);
      ctx.lineTo(-6, -r + 12);
      ctx.lineTo(6, -r + 12);
      ctx.closePath();
      ctx.fill();

      // numeral
      ctx.fillStyle = deg === null ? FAINT : FG;
      ctx.font = '700 ' + Math.round(r * 0.45) + 'px ' + MONO;
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillText(deg === null ? '---' : String(Math.round(deg)).padStart(3, '0'), 0, 0);
      if (opts.unit) {
        ctx.fillStyle = DIM;
        ctx.font = Math.round(r * 0.15) + 'px ' + MONO;
        ctx.fillText(opts.unit, 0, r * 0.32);
      }
      ctx.restore();
    }

    draw(null);
    return draw;
  }

  /**
   * Sparkline. data: array of numbers (nulls break the line).
   * opts: {color, fill, min, max}  Returns draw(data).
   */
  function sparkline(canvas, opts) {
    opts = opts || {};
    var color = opts.color || GREEN;

    function draw(data) {
      var g = fit(canvas);
      if (!g) return;
      var ctx = g.ctx, w = g.w, h = g.h;
      if (!data || !data.length) {
        ctx.fillStyle = FAINT;
        ctx.font = '12px ' + MONO;
        ctx.textAlign = 'center';
        ctx.fillText('no data', w / 2, h / 2);
        return;
      }
      var lo = opts.min, hi = opts.max;
      if (lo === undefined || hi === undefined) {
        lo = Infinity; hi = -Infinity;
        for (var i = 0; i < data.length; i++) {
          if (data[i] === null || isNaN(data[i])) continue;
          if (data[i] < lo) lo = data[i];
          if (data[i] > hi) hi = data[i];
        }
        if (!isFinite(lo)) { lo = 0; hi = 1; }
        if (hi - lo < 1e-9) { hi = lo + 1; }
      }
      var pad = 2;
      function x(i) { return pad + (w - 2 * pad) * (i / Math.max(1, data.length - 1)); }
      function y(v) { return h - pad - (h - 2 * pad) * ((v - lo) / (hi - lo)); }

      if (opts.fill !== false) {
        ctx.beginPath();
        var started = false, base = h - pad;
        for (var j = 0; j < data.length; j++) {
          if (data[j] === null || isNaN(data[j])) { started = false; continue; }
          if (!started) { ctx.moveTo(x(j), base); ctx.lineTo(x(j), y(data[j])); started = true; }
          else ctx.lineTo(x(j), y(data[j]));
        }
        ctx.lineTo(x(data.length - 1), base);
        ctx.closePath();
        ctx.fillStyle = color + '22';
        ctx.fill();
      }

      ctx.beginPath();
      ctx.strokeStyle = color;
      ctx.lineWidth = 1.5;
      var pen = false;
      for (var k = 0; k < data.length; k++) {
        if (data[k] === null || isNaN(data[k])) { pen = false; continue; }
        if (!pen) { ctx.moveTo(x(k), y(data[k])); pen = true; }
        else ctx.lineTo(x(k), y(data[k]));
      }
      ctx.stroke();

      // last value marker
      for (var m = data.length - 1; m >= 0; m--) {
        if (data[m] !== null && !isNaN(data[m])) {
          ctx.fillStyle = color;
          ctx.beginPath();
          ctx.arc(x(m), y(data[m]), 2.5, 0, Math.PI * 2);
          ctx.fill();
          break;
        }
      }
    }

    draw([]);
    return draw;
  }

  return { dial: dial, compass: compass, sparkline: sparkline, fit: fit,
           GREEN: GREEN, AMBER: AMBER, RED: RED, FG: FG, DIM: DIM, FAINT: FAINT, MONO: MONO };
})();
