// Scai treemap：squarified 矩形树图（SVG，无第三方库）
// 输入节点: { key, name, size, color }；点击回调 onPick(node)
(function () {
  // Bruls et al. squarified treemap
  function worst(row, length) {
    const sum = row.reduce((acc, n) => acc + n.area, 0);
    if (sum === 0 || length === 0) return Infinity;
    let max = 0, min = Infinity;
    for (const n of row) {
      const side = (sum * n.area) / length;
      max = Math.max(max, Math.max(side / length, length / side));
      min = Math.min(min, Math.max(side / length, length / side));
    }
    return row.length === 0 ? Infinity : max / min;
  }

  function squarify(items, row, x, y, w, h, out) {
    if (items.length === 0) {
      layOutRow(row, x, y, w, h, out);
      return;
    }
    const length = Math.min(w, h);
    const item = items[0];
    const newRow = row.concat([item]);
    if (worst(row, length) >= worst(newRow, length)) {
      squarify(items.slice(1), newRow, x, y, w, h, out);
    } else {
      const placed = layOutRow(row, x, y, w, h, out);
      squarify(items, [], placed.x, placed.y, placed.w, placed.h, out);
    }
  }

  function layOutRow(row, x, y, w, h, out) {
    if (row.length === 0) return { x, y, w, h };
    const sum = row.reduce((acc, n) => acc + n.area, 0);
    const horizontal = w >= h; // 沿短边方向铺一行
    let offset = 0;
    for (const n of row) {
      const frac = sum === 0 ? 0 : n.area / sum;
      if (horizontal) {
        const nw = w * frac;
        out.push({ node: n, x, y, w: nw, h });
        offset += nw;
      } else {
        const nh = h * frac;
        out.push({ node: n, x, y: y + offset, w, h: nh });
        offset += nh;
      }
    }
    return horizontal
      ? { x: x + offset, y, w: w - offset, h }
      : { x, y: y + offset, w, h: h - offset };
  }

  function render(container, nodes, onPick) {
    container.innerHTML = "";
    if (!nodes.length) {
      container.innerHTML = '<p class="detail-empty" style="margin-top:12px">暂无目录数据</p>';
      return;
    }
    const rect = container.getBoundingClientRect();
    const width = Math.max(80, Math.floor(rect.width));
    const height = Math.max(60, Math.floor(rect.height));
    const svgNS = "http://www.w3.org/2000/svg";
    const svg = document.createElementNS(svgNS, "svg");
    svg.setAttribute("viewBox", `0 0 ${width} ${height}`);
    svg.setAttribute("preserveAspectRatio", "none");

    const total = nodes.reduce((acc, n) => acc + n.size, 0);
    const placed = [];
    const items = nodes
      .map((n) => ({ ...n, area: total > 0 ? (n.size / total) * (width * height) : 0 }))
      .sort((a, b) => b.area - a.area);
    squarify(items, [], 0, 0, width, height, placed);

    for (const p of placed) {
      if (p.w < 1 || p.h < 1) continue;
      const g = document.createElementNS(svgNS, "g");
      const r = document.createElementNS(svgNS, "rect");
      r.setAttribute("x", Math.floor(p.x));
      r.setAttribute("y", Math.floor(p.y));
      r.setAttribute("width", Math.max(1, Math.floor(p.w) - 1));
      r.setAttribute("height", Math.max(1, Math.floor(p.h) - 1));
      r.setAttribute("rx", 3);
      r.setAttribute("fill", p.node.color);
      r.setAttribute("class", "tm-rect" + (p.node.key ? "" : " other"));
      if (p.node.key) {
        r.addEventListener("click", () => onPick && onPick(p.node));
        const title = document.createElementNS(svgNS, "title");
        title.textContent = `${p.node.name}：${p.node.human}`;
        r.appendChild(title);
      }
      g.appendChild(r);
      // 标签（足够大时才显示）
      if (p.w > 52 && p.h > 30) {
        const label = document.createElementNS(svgNS, "text");
        label.setAttribute("class", "tm-label");
        label.setAttribute("x", Math.floor(p.x) + 7);
        label.setAttribute("y", Math.floor(p.y) + 15);
        label.textContent = clip(p.node.name, Math.floor((p.w - 12) / 6.2));
        g.appendChild(label);
        if (p.h > 62) {
          const size = document.createElementNS(svgNS, "text");
          size.setAttribute("class", "tm-label");
          size.setAttribute("x", Math.floor(p.x) + 7);
          size.setAttribute("y", Math.floor(p.y) + 31);
          size.setAttribute("opacity", "0.92");
          size.textContent = p.node.human;
          g.appendChild(size);
        }
      }
      svg.appendChild(g);
    }
    container.appendChild(svg);
  }

  function clip(text, max) {
    if (!max || max < 2) return "";
    return text.length > max ? text.slice(0, max - 1) + "…" : text;
  }

  window.scaiTreemap = { render };
})();
