/**
 * Visual automation editor (Drawflow — https://github.com/jerosoler/Drawflow, MIT).
 */
(function () {
  const root = document.getElementById('automation-flow-app');
  if (!root || typeof Drawflow === 'undefined') {
    return;
  }

  const configEl = document.getElementById('automation-flow-config');
  const defEl = document.getElementById('automation-flow-definition');
  const config = JSON.parse((configEl && configEl.textContent) || root.dataset.config || '{}');
  const definition = JSON.parse((defEl && defEl.textContent) || root.dataset.definition || '{}');
  const initialProcessCourseSlug = root.dataset.courseSlug || 'elke-en';
  const ENTRY_MODES_NEED_COURSE = { demo_grant: true, payment_course: true };
  const marketingTemplates = new Set(config.marketing_templates || []);

  function templateNeedsCourseSlug(template) {
    return marketingTemplates.has(String(template || '').trim());
  }
  const jsonField = document.querySelector('textarea[name="definition_json"]');
  const jsonInline = document.getElementById('automation-flow-json-inline');
  const jsonDrawer = document.getElementById('automation-flow-json-drawer');
  const form = jsonField ? jsonField.closest('form') : null;

  const canvas = document.getElementById('drawflow');
  const propsPanel = document.getElementById('automation-flow-props');
  if (propsPanel) {
    document.addEventListener('click', (e) => {
      propsPanel.querySelectorAll('.automation-staff-multiselect.is-open').forEach((wrap) => {
        if (!wrap.contains(e.target)) {
          wrap.classList.remove('is-open');
          const btn = wrap.querySelector('.automation-staff-multiselect__trigger');
          const panel = wrap.querySelector('.automation-staff-multiselect__panel');
          if (btn) {
            btn.setAttribute('aria-expanded', 'false');
          }
          if (panel) {
            panel.hidden = true;
          }
          const propsWrap = wrap.closest('.automation-flow-props-wrap');
          if (propsWrap) {
            propsWrap.classList.remove('automation-props--staff-open');
          }
        }
      });
    });
  }
  const palette = document.getElementById('automation-flow-palette');
  const undoBtn = document.getElementById('automation-flow-undo');
  const shell = document.getElementById('automation-flow-shell');
  const fsBtn = document.getElementById('automation-flow-fullscreen');
  const removeConnBtn = document.getElementById('automation-flow-remove-connection');
  const zoomSlider = document.getElementById('automation-flow-zoom-slider');
  const zoomLabel = document.getElementById('automation-flow-zoom-label');
  let fullscreenOn = false;
  let fsPlaceholder = null;
  let retainFullscreenLayout = false;
  const FS_RESTORE_KEY = 'wwmAutomationRestoreFullscreen';

  const editor = new Drawflow(canvas);
  editor.reroute = true;
  editor.curvature = 0.4;
  editor.start();

  (function setupCanvasPanWithRightMouse() {
    const surface = editor.container || canvas;
    if (!surface) {
      return;
    }

    function isCanvasBackgroundTarget(target) {
      if (!target || !surface.contains(target)) {
        return false;
      }
      return !target.closest('.drawflow-node');
    }

    function isRightMouseButton(e) {
      return e.button === 2 || e.which === 3;
    }

    function applyCanvasTransform() {
      if (!editor.precanvas) {
        return;
      }
      editor.precanvas.style.transform = 'translate(' + editor.canvas_x + 'px, ' + editor.canvas_y
        + 'px) scale(' + editor.zoom + ')';
    }

    function clearFlowSelection() {
      editor.editor_selected = false;
      editor.drag = false;
      document.querySelectorAll('.drawflow-node.selected').forEach((el) => el.classList.remove('selected'));
      editor.node_selected = null;
      selectedDfId = null;
      removeAllNodeDeleteButtons();
      if (propsPanel) {
        propsPanel.innerHTML = '<p class="field-hint">Выберите блок на схеме.</p>';
      }
    }

    function setPanningUi(active) {
      surface.classList.toggle('automation-flow-panning', active);
      document.body.classList.toggle('automation-flow-rmb-pan', active);
    }

    let rmbPanActive = false;
    let panLastX = 0;
    let panLastY = 0;
    let panPointerId = null;
    let panMoveBound = null;
    let panEndBound = null;

    function stopRmbPan() {
      if (!rmbPanActive) {
        return;
      }
      rmbPanActive = false;
      panPointerId = null;
      editor.editor_selected = false;
      setPanningUi(false);
      if (panMoveBound) {
        document.removeEventListener('pointermove', panMoveBound, true);
        document.removeEventListener('mousemove', panMoveBound, true);
        panMoveBound = null;
      }
      if (panEndBound) {
        document.removeEventListener('pointerup', panEndBound, true);
        document.removeEventListener('mouseup', panEndBound, true);
        panEndBound = null;
      }
    }

    function onRmbPanMove(e) {
      if (!rmbPanActive) {
        return;
      }
      if (panPointerId !== null && e.pointerId !== undefined && e.pointerId !== panPointerId) {
        return;
      }
      if (typeof e.buttons === 'number' && (e.buttons & 2) === 0 && e.type.indexOf('mouse') !== -1) {
        stopRmbPan();
        return;
      }
      const dx = e.clientX - panLastX;
      const dy = e.clientY - panLastY;
      if (dx === 0 && dy === 0) {
        return;
      }
      panLastX = e.clientX;
      panLastY = e.clientY;
      editor.canvas_x += dx;
      editor.canvas_y += dy;
      applyCanvasTransform();
    }

    function onRmbPanEnd(e) {
      if (!rmbPanActive) {
        return;
      }
      if (panPointerId !== null && e.pointerId !== undefined && e.pointerId !== panPointerId) {
        return;
      }
      if (e.type === 'mouseup' && !isRightMouseButton(e) && typeof e.buttons === 'number' && e.buttons !== 0) {
        return;
      }
      stopRmbPan();
    }

    function startRmbPan(e) {
      rmbPanActive = true;
      panLastX = e.clientX;
      panLastY = e.clientY;
      panPointerId = typeof e.pointerId === 'number' ? e.pointerId : null;
      editor.editor_selected = false;
      editor.drag = false;
      setPanningUi(true);
      panMoveBound = onRmbPanMove;
      panEndBound = onRmbPanEnd;
      document.addEventListener('pointermove', panMoveBound, true);
      document.addEventListener('mousemove', panMoveBound, true);
      document.addEventListener('pointerup', panEndBound, true);
      document.addEventListener('mouseup', panEndBound, true);
      if (surface.setPointerCapture && panPointerId !== null) {
        try {
          surface.setPointerCapture(panPointerId);
        } catch (err) {
          // ignore
        }
      }
    }

    function onCanvasPointerDownCapture(e) {
      if (!isCanvasBackgroundTarget(e.target)) {
        return;
      }
      if (e.button === 0) {
        editor.editor_selected = false;
        e.stopPropagation();
        e.stopImmediatePropagation();
        clearFlowSelection();
        return;
      }
      if (isRightMouseButton(e)) {
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();
        startRmbPan(e);
      }
    }

    surface.addEventListener('mousedown', onCanvasPointerDownCapture, true);
    surface.addEventListener('pointerdown', onCanvasPointerDownCapture, true);

    surface.addEventListener('mousedown', (e) => {
      if (e.button === 0 && isCanvasBackgroundTarget(e.target)) {
        editor.editor_selected = false;
      }
    });

    surface.addEventListener('mousemove', (e) => {
      if (rmbPanActive) {
        return;
      }
      if (editor.editor_selected && !editor.drag && (e.buttons & 1) === 1) {
        editor.editor_selected = false;
        applyCanvasTransform();
      }
    }, true);

    window.addEventListener('blur', stopRmbPan);

    surface.addEventListener('contextmenu', (e) => {
      if (isCanvasBackgroundTarget(e.target)) {
        e.preventDefault();
      }
    });
  })();

  let selectedDfId = null;
  const dfIdToKey = new Map();
  const keyToDfId = new Map();

  const history = [];
  const HISTORY_MAX = 40;
  let restoring = false;
  let historyTimer = null;

  function normalizeDfId(id) {
    const s = String(id);
    return s.startsWith('node-') ? s.slice(5) : s;
  }

  function snapshotDrawflow() {
    return JSON.stringify(editor.export());
  }

  function pushHistory() {
    if (restoring) {
      return;
    }
    const snap = snapshotDrawflow();
    if (history.length > 0 && history[history.length - 1] === snap) {
      return;
    }
    history.push(snap);
    if (history.length > HISTORY_MAX) {
      history.shift();
    }
    updateUndoButton();
  }

  function scheduleHistory() {
    if (historyTimer) {
      clearTimeout(historyTimer);
    }
    historyTimer = setTimeout(pushHistory, 400);
  }

  function updateUndoButton() {
    if (undoBtn) {
      undoBtn.disabled = history.length === 0;
    }
  }

  function restoreSnapshot(json) {
    restoring = true;
    try {
      editor.import(JSON.parse(json));
      rebuildMapsFromCanvas();
      syncJsonField();
      selectedDfId = null;
      if (propsPanel) {
        propsPanel.innerHTML = '<p class="field-hint">Выберите блок на схеме.</p>';
      }
    } finally {
      restoring = false;
    }
  }

  function undo() {
    if (history.length === 0) {
      return;
    }
    const snap = history.pop();
    updateUndoButton();
    restoreSnapshot(snap);
    syncJsonField();
  }

  function rebuildMapsFromCanvas() {
    dfIdToKey.clear();
    keyToDfId.clear();
    const data = editor.export().drawflow?.Home?.data || {};
    Object.keys(data).forEach((id) => {
      const n = data[id];
      const key = n.data?.node_id || ('node_' + id);
      if (!n.data.node_id) {
        n.data.node_id = key;
        editor.updateNodeDataFromId(id, n.data);
      }
      registerNode(id, key, n.data);
      updateNodeHtml(id);
    });
  }

  function removeAllNodeDeleteButtons() {
    document.querySelectorAll('.automation-node-delete').forEach((el) => el.remove());
  }

  function isNodeVisuallySelected(dfId) {
    const id = normalizeDfId(dfId);
    const nodeEl = document.getElementById('node-' + id);
    if (!nodeEl) {
      return false;
    }
    if (nodeEl.classList.contains('selected')) {
      return true;
    }
    return editor.node_selected === nodeEl;
  }

  function syncNodeDeleteButton() {
    removeAllNodeDeleteButtons();
    if (!selectedDfId) {
      return;
    }
    const dfId = normalizeDfId(selectedDfId);
    if (!isNodeVisuallySelected(dfId)) {
      return;
    }
    const nodeEl = document.getElementById('node-' + dfId);
    if (!nodeEl) {
      return;
    }
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'automation-node-delete';
    btn.setAttribute('aria-label', 'Удалить блок');
    btn.title = 'Удалить блок';
    btn.innerHTML = '<span aria-hidden="true">&times;</span>';
    btn.addEventListener('pointerdown', (e) => {
      e.stopPropagation();
    });
    btn.addEventListener('mousedown', (e) => {
      e.stopPropagation();
    });
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      e.preventDefault();
      removeNodeWithConfirm(dfId);
    });
    nodeEl.appendChild(btn);
  }

  function removeNodeWithConfirm(dfId) {
    const id = normalizeDfId(dfId);
    const n = editor.getNodeFromId(id);
    if (!n) {
      return;
    }
    const label = n.data.label || n.data.type || id;
    const runRemove = function () {
      pushHistory();
      unregisterNode(id);
      editor.removeNodeId('node-' + id);
      if (String(selectedDfId) === String(id)) {
        selectedDfId = null;
      }
      removeAllNodeDeleteButtons();
      if (propsPanel) {
        propsPanel.innerHTML = '<p class="field-hint">Выберите блок на схеме.</p>';
      }
      syncJsonField();
    };
    if (typeof wwmAdminConfirm === 'function') {
      wwmAdminConfirm({
        title: 'Удалить блок?',
        message: 'Блок «' + label + '» и его связи будут удалены с холста.',
        confirmLabel: 'Удалить',
        danger: true,
      }).then((ok) => {
        if (ok) {
          runRemove();
        }
      });
      return;
    }
    runRemove();
  }

  editor.on('nodeSelected', function (id) {
    selectedDfId = normalizeDfId(id);
    editor.connection_selected = null;
    updateRemoveConnBtn();
    renderProps(selectedDfId);
    window.requestAnimationFrame(function () {
      syncNodeDeleteButton();
    });
  });

  editor.on('nodeUnselected', function () {
    removeAllNodeDeleteButtons();
  });

  editor.on('connectionSelected', function () {
    selectedDfId = null;
    removeAllNodeDeleteButtons();
    updateRemoveConnBtn();
    if (propsPanel) {
      propsPanel.innerHTML = (
        '<h3 class="automation-props-title">Связь</h3>'
        + '<p class="field-hint">Чтобы переназначить: удалите эту связь (кнопка «Удалить связь» или Delete), затем потяните линию от кружка-выхода одного блока к кружку-входу другого.</p>'
        + '<p class="field-hint">Двойной клик по линии добавляет изгиб (reroute).</p>'
      );
    }
  });

  function zoomPercent() {
    return Math.round(editor.zoom * 100);
  }

  function updateZoomUi() {
    const pct = zoomPercent();
    if (zoomLabel) {
      zoomLabel.textContent = pct + '%';
    }
    if (zoomSlider) {
      zoomSlider.value = String(Math.min(160, Math.max(50, pct)));
    }
  }

  function setZoomRatio(ratio) {
    const r = Math.min(editor.zoom_max, Math.max(editor.zoom_min, ratio));
    editor.zoom = r;
    editor.zoom_refresh();
    updateZoomUi();
  }

  function panToNode(dfId) {
    const id = normalizeDfId(dfId);
    const n = editor.getNodeFromId(id);
    const el = document.getElementById('node-' + id);
    if (!n || !el) {
      return false;
    }
    const zoom = editor.zoom;
    const cx = (typeof n.pos_x === 'number' ? n.pos_x : el.offsetLeft) + el.offsetWidth / 2;
    const cy = (typeof n.pos_y === 'number' ? n.pos_y : el.offsetTop) + el.offsetHeight / 2;
    const w = editor.container.clientWidth;
    const h = editor.container.clientHeight;
    editor.canvas_x = w / 2 - cx * zoom;
    editor.canvas_y = h / 2 - cy * zoom;
    editor.precanvas.style.transform = 'translate(' + editor.canvas_x + 'px, ' + editor.canvas_y + 'px) scale(' + zoom + ')';
    return true;
  }

  function findStartNodeDfId() {
    if (keyToDfId.has('start')) {
      return keyToDfId.get('start');
    }
    const home = editor.export().drawflow?.Home?.data || {};
    const ids = Object.keys(home);
    for (let i = 0; i < ids.length; i += 1) {
      const row = home[ids[i]];
      if (row.data?.type === 'trigger' || row.name === 'trigger') {
        return ids[i];
      }
    }
    return ids.length > 0 ? ids[0] : null;
  }

  function focusStartNode() {
    const dfId = findStartNodeDfId();
    if (!dfId) {
      if (typeof wwmAdminAlert === 'function') {
        wwmAdminAlert({ title: 'Старт не найден', message: 'На схеме нет блока «Старт» (start).' });
      }
      return;
    }
    panToNode(dfId);
    selectedDfId = String(dfId);
    const nodeEl = document.getElementById('node-' + dfId);
    if (nodeEl) {
      document.querySelectorAll('.drawflow-node.selected').forEach((el) => el.classList.remove('selected'));
      nodeEl.classList.add('selected');
      editor.node_selected = nodeEl;
    }
    renderProps(dfId);
    syncNodeDeleteButton();
  }

  editor.on('zoom', function () {
    updateZoomUi();
  });

  editor.on('connectionStart', function () {
    if (!restoring) {
      pushHistory();
    }
  });

  editor.on('connectionCreated', function () {
    if (!restoring) {
      syncJsonField();
    }
  });

  editor.on('connectionRemoved', function () {
    if (!restoring) {
      syncJsonField();
    }
  });

  (function setupConnectionDetachByDrag() {
    const surface = editor.container || canvas;
    if (!surface || typeof editor.removeSingleConnection !== 'function') {
      return;
    }

    const MOVE_PX = 10;
    let outputDetach = null;
    let inputDetach = null;

    function removeConnectionPair(outNodeId, inNodeId, outClass, inClass) {
      editor.removeSingleConnection(
        String(outNodeId),
        String(inNodeId),
        String(outClass),
        String(inClass)
      );
    }

    function parseInputPort(target) {
      if (!target || !target.closest) {
        return null;
      }
      const el = target.closest('.input');
      if (!el) {
        return null;
      }
      const nodeEl = el.closest('.drawflow-node');
      if (!nodeEl || !nodeEl.id || !nodeEl.id.startsWith('node-')) {
        return null;
      }
      let inputClass = '';
      el.classList.forEach((cls) => {
        if (cls.indexOf('input_') === 0) {
          inputClass = cls;
        }
      });
      if (!inputClass) {
        return null;
      }
      return { inNodeId: nodeEl.id.slice(5), inputClass };
    }

    function getInputConnections(inNodeId, inputClass) {
      const node = editor.getNodeFromId(inNodeId);
      const list = node?.inputs?.[inputClass]?.connections;
      if (!Array.isArray(list) || list.length === 0) {
        return [];
      }
      return list.map((c) => ({
        outNodeId: String(c.node),
        outClass: String(c.input),
      }));
    }

    function trackOutputDetachMove() {
      if (outputDetach && editor.connection) {
        outputDetach.moved = true;
      }
    }

    surface.addEventListener('mousemove', trackOutputDetachMove, true);
    surface.addEventListener('pointermove', trackOutputDetachMove, true);

    editor.on('connectionStart', (info) => {
      if (!info || info.output_id === undefined) {
        outputDetach = null;
        return;
      }
      const outputId = String(info.output_id);
      const outputClass = String(info.output_class || 'output_1');
      const node = editor.getNodeFromId(outputId);
      const list = node?.outputs?.[outputClass]?.connections;
      if (!Array.isArray(list) || list.length === 0) {
        outputDetach = null;
        return;
      }
      outputDetach = {
        outputId,
        outputClass,
        connections: list.map((c) => ({
          inNodeId: String(c.node),
          inClass: String(c.output),
        })),
        moved: false,
      };
    });

    editor.on('connectionCreated', () => {
      outputDetach = null;
    });

    editor.on('connectionCancel', () => {
      if (!outputDetach || !outputDetach.moved) {
        outputDetach = null;
        return;
      }
      outputDetach.connections.forEach((c) => {
        removeConnectionPair(outputDetach.outputId, c.inNodeId, outputDetach.outputClass, c.inClass);
      });
      outputDetach = null;
      if (!restoring) {
        syncJsonField();
      }
    });

    function cleanupInputDetachListeners() {
      document.removeEventListener('pointermove', onInputDetachMove, true);
      document.removeEventListener('mousemove', onInputDetachMove, true);
      document.removeEventListener('pointerup', endInputDetach, true);
      document.removeEventListener('mouseup', endInputDetach, true);
      surface.classList.remove('automation-flow-detach-cable');
    }

    function onInputDetachMove(e) {
      if (!inputDetach) {
        return;
      }
      const dx = e.clientX - inputDetach.startX;
      const dy = e.clientY - inputDetach.startY;
      if (dx * dx + dy * dy < MOVE_PX * MOVE_PX) {
        return;
      }
      inputDetach.moved = true;
      surface.classList.add('automation-flow-detach-cable');
      if (!inputDetach.historySaved && !restoring) {
        pushHistory();
        inputDetach.historySaved = true;
      }
    }

    function endInputDetach(e) {
      if (!inputDetach) {
        return;
      }
      const state = inputDetach;
      inputDetach = null;
      cleanupInputDetachListeners();

      if (!state.moved) {
        return;
      }
      if (e.target && e.target.closest && e.target.closest('.input')) {
        return;
      }
      state.connections.forEach((c) => {
        removeConnectionPair(c.outNodeId, state.inNodeId, c.outClass, state.inputClass);
      });
      if (!restoring) {
        syncJsonField();
      }
    }

    surface.addEventListener('mousedown', (e) => {
      if (e.button !== 0) {
        return;
      }
      const port = parseInputPort(e.target);
      if (!port) {
        return;
      }
      const connections = getInputConnections(port.inNodeId, port.inputClass);
      if (connections.length === 0) {
        return;
      }
      inputDetach = {
        inNodeId: port.inNodeId,
        inputClass: port.inputClass,
        connections,
        startX: e.clientX,
        startY: e.clientY,
        moved: false,
        historySaved: false,
      };
      document.addEventListener('pointermove', onInputDetachMove, true);
      document.addEventListener('mousemove', onInputDetachMove, true);
      document.addEventListener('pointerup', endInputDetach, true);
      document.addEventListener('mouseup', endInputDetach, true);
    }, true);
  })();

  editor.on('nodeRemoved', function () {
    if (!restoring) {
      syncJsonField();
    }
  });

  editor.on('nodeMoved', function () {
    if (!restoring) {
      syncJsonField();
    }
  });

  function slugifyKey(base) {
    let key = base.replace(/[^a-z0-9_]/gi, '_').toLowerCase();
    if (!key) {
      key = 'node';
    }
    let n = 1;
    let candidate = key;
    const used = new Set(definition.nodes ? Object.keys(definition.nodes) : []);
    keyToDfId.forEach((_, k) => used.add(k));
    while (used.has(candidate)) {
      candidate = key + '_' + n;
      n += 1;
    }
    return candidate;
  }

  function typeMeta(type) {
    return (config.palette || []).find((p) => p.id === type) || {};
  }

  function nodeCssClass(type) {
    const meta = typeMeta(type);
    const parts = [meta.class || '', 'automation-node--t-' + (type || 'step')];
    return parts.filter(Boolean).join(' ');
  }

  function statsForNode(nodeId) {
    const all = config.step_stats || {};
    const row = all[nodeId];
    if (!row || typeof row !== 'object') {
      return { hits: 0, unique_users: 0, last_at: null };
    }
    return {
      hits: Number(row.hits) || 0,
      unique_users: Number(row.unique_users) || 0,
      last_at: row.last_at || null,
    };
  }

  function waitingForNode(nodeId) {
    const all = config.node_waiting || {};
    return Array.isArray(all[nodeId]) ? all[nodeId] : [];
  }

  function occupancyForNode(nodeId) {
    const waiting = waitingForNode(nodeId);
    if (waiting.length > 0) {
      return waiting.length;
    }
    const occ = config.node_occupancy || {};
    return Number(occ[nodeId]) || 0;
  }

  function applyNodeStats(dfId) {
    const id = normalizeDfId(dfId);
    const nodeEl = document.getElementById('node-' + id);
    if (!nodeEl) {
      return;
    }
    let nodeId = '';
    try {
      const n = editor.getNodeFromId(id);
      nodeId = String(n?.data?.node_id || '');
    } catch (e) {
      return;
    }
    if (nodeId === '') {
      return;
    }
    const waitN = occupancyForNode(nodeId);
    const passN = statsForNode(nodeId).unique_users;
    let waitBtn = nodeEl.querySelector(':scope > .automation-node-stat--wait');
    let passBtn = nodeEl.querySelector(':scope > .automation-node-stat--pass');
    if (!waitBtn) {
      waitBtn = document.createElement('button');
      waitBtn.type = 'button';
      waitBtn.className = 'automation-node-stat automation-node-stat--wait';
      nodeEl.appendChild(waitBtn);
    }
    if (!passBtn) {
      passBtn = document.createElement('button');
      passBtn.type = 'button';
      passBtn.className = 'automation-node-stat automation-node-stat--pass';
      nodeEl.appendChild(passBtn);
    }
    waitBtn.dataset.nodeId = nodeId;
    waitBtn.dataset.kind = 'waiting';
    waitBtn.textContent = String(waitN);
    waitBtn.title = 'Сейчас на блоке: ' + waitN;
    waitBtn.setAttribute('aria-label', 'Сейчас на блоке: ' + waitN);
    waitBtn.classList.toggle('is-empty', waitN === 0);

    passBtn.dataset.nodeId = nodeId;
    passBtn.dataset.kind = 'passed';
    passBtn.textContent = String(passN);
    passBtn.title = 'Прошли блок: ' + passN;
    passBtn.setAttribute('aria-label', 'Прошли блок: ' + passN);
    passBtn.classList.toggle('is-empty', passN === 0);
  }

  function applyAllNodeStats() {
    const home = editor.drawflow?.drawflow?.Home?.data || {};
    Object.keys(home).forEach(applyNodeStats);
  }

  function formatPersonDate(iso) {
    if (!iso) {
      return '—';
    }
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) {
      return String(iso);
    }
    const p = (n) => String(n).padStart(2, '0');
    return p(d.getDate()) + '.' + p(d.getMonth() + 1) + '.' + d.getFullYear()
      + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
  }

  function uniquePeople(rows) {
    const seen = new Map();
    (rows || []).forEach((row) => {
      const uid = Number(row.user_id) || 0;
      if (uid <= 0) {
        return;
      }
      const prev = seen.get(uid);
      if (!prev || String(row.created_at || '') > String(prev.created_at || '')) {
        seen.set(uid, row);
      }
    });
    return Array.from(seen.values());
  }

  function ensurePeopleModal() {
    let el = document.getElementById('automation-node-people');
    if (el) {
      return el;
    }
    el = document.createElement('div');
    el.id = 'automation-node-people';
    el.className = 'modal automation-node-people';
    el.innerHTML = ''
      + '<div class="modal-backdrop" data-people-close></div>'
      + '<div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="automation-node-people-title">'
      + '<button type="button" class="automation-node-people__close" data-people-close aria-label="Закрыть">×</button>'
      + '<h2 id="automation-node-people-title">Прошедшие блок</h2>'
      + '<p class="field-hint automation-node-people__hint"></p>'
      + '<div class="admin-table-wrap admin-table-wrap--profile">'
      + '<table class="admin-table admin-table-compact admin-table--profile">'
      + '<thead><tr><th>Имя</th><th>Email</th><th class="col-date">Дата</th></tr></thead>'
      + '<tbody></tbody></table></div>'
      + '</div>';
    document.body.appendChild(el);
    el.addEventListener('click', (e) => {
      if (e.target.closest('[data-people-close]')) {
        el.classList.remove('is-open');
      }
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && el.classList.contains('is-open')) {
        el.classList.remove('is-open');
      }
    });
    return el;
  }

  function renderPeopleRows(tbody, rows, emptyText) {
    const people = uniquePeople(rows);
    if (people.length === 0) {
      tbody.innerHTML = '<tr><td colspan="3" class="field-hint">' + escapeHtml(emptyText) + '</td></tr>';
      return;
    }
    tbody.innerHTML = people.map((row) => {
      const name = String(row.name || '').trim();
      const email = String(row.email || '').trim();
      const url = String(row.student_url || ('/admin/students/' + (row.user_id || '')));
      const label = name !== '' ? name : (email || 'Ученик');
      return '<tr>'
        + '<td><a href="' + escapeHtml(url) + '">' + escapeHtml(label) + '</a></td>'
        + '<td>' + (email !== '' ? '<a href="' + escapeHtml(url) + '">' + escapeHtml(email) + '</a>' : '—') + '</td>'
        + '<td class="col-date">' + escapeHtml(formatPersonDate(row.created_at)) + '</td>'
        + '</tr>';
    }).join('');
  }

  function openNodePeople(nodeId, kind) {
    const modal = ensurePeopleModal();
    const title = modal.querySelector('#automation-node-people-title');
    const hint = modal.querySelector('.automation-node-people__hint');
    const tbody = modal.querySelector('tbody');
    const isWaiting = kind === 'waiting';
    title.textContent = isWaiting ? 'Сейчас на блоке' : 'Прошедшие блок';
    let label = nodeId;
    const home = editor.export().drawflow?.Home?.data || {};
    Object.keys(home).forEach((dfId) => {
      const d = home[dfId] && home[dfId].data;
      if (d && String(d.node_id || '') === nodeId) {
        label = String(d.label || nodeId);
      }
    });
    hint.textContent = label;
    tbody.innerHTML = '<tr><td colspan="3" class="field-hint">Загрузка…</td></tr>';
    modal.classList.add('is-open');

    if (isWaiting) {
      renderPeopleRows(tbody, waitingForNode(nodeId), 'Никого нет на этом блоке.');
      return;
    }

    const base = String(config.step_events_url || '');
    if (base === '') {
      renderPeopleRows(tbody, [], 'Список недоступен.');
      return;
    }
    fetch(base + '?node_id=' + encodeURIComponent(nodeId), { credentials: 'same-origin' })
      .then((r) => {
        if (!r.ok) {
          throw new Error('http ' + r.status);
        }
        return r.json();
      })
      .then((data) => {
        const events = Array.isArray(data.events) ? data.events : [];
        renderPeopleRows(tbody, events, 'Пока никто не проходил этот блок.');
      })
      .catch(() => {
        renderPeopleRows(tbody, [], 'Не удалось загрузить список.');
      });
  }

  function nodeHtml(data) {
    const type = data.type || 'step';
    const meta = typeMeta(type);
    const title = data.label || data.node_id || type;
    const sub = subtitleFor(data);
    const badge = meta.short || meta.label || type;
    return (
      '<div class="automation-df-node automation-df-node--' + escapeHtml(type) + '">'
      + '<span class="automation-df-node__badge">' + escapeHtml(badge) + '</span>'
      + '<div class="automation-df-node__title">' + escapeHtml(title) + '</div>'
      + (sub ? '<div class="automation-df-node__sub">' + escapeHtml(sub) + '</div>' : '')
      + '</div>'
    );
  }

  function subtitleFor(data) {
    switch (data.type) {
      case 'delay':
        return formatDelay(data.seconds);
      case 'send_template': {
        const bits = [templateLabel(data.template)];
        if (data.skip_if_paid_course) {
          bits.push('skip if paid');
        }
        const cslug = String(data.course_slug || '').trim();
        if (cslug && templateNeedsCourseSlug(data.template)) {
          bits.push(cslug);
        }
        return bits.filter(Boolean).join(' · ');
      }
      case 'trigger': {
        const mode = entryModeLabel(data.entry_mode || (config.automation && config.automation.entry_mode));
        const slug = String(data.process_course_slug || '').trim();
        const bits = [mode];
        if (slug) {
          bits.push(courseLabelForSlug(slug));
        }
        return bits.join(' · ');
      }
      case 'condition':
        return conditionLabel(data.condition);
      case 'grant_demo': {
        const slug = data.course_slug || processCourseSlug();
        const bits = [courseLabelForSlug(slug)];
        if (data.skip_if_paid) bits.push('skip paid');
        if (data.skip_if_demo_active) bits.push('skip active demo');
        return bits.filter(Boolean).join(' · ');
      }
      case 'revoke_demo':
        return 'устарел — удалите';
      case 'end':
        return outcomeLabel(data.outcome);
      case 'notify_staff': {
        const ids = staffAdminIds(data);
        if (ids.length) {
          const names = ids.map((id) => {
            const opt = (config.staff_admins || []).find((o) => String(o.value) === id);
            return opt ? (opt.name || opt.email) : id;
          });
          return names.slice(0, 2).join(', ') + (names.length > 2 ? '…' : '');
        }
        const def = String(config.staff_notify_default_email || '').trim();
        return def ? '→ config ' + def : 'только лог';
      }
      default:
        return data.type || '';
    }
  }

  function templateLabel(id) {
    const t = (config.templates || []).find((x) => x.value === id);
    return t ? t.label : (id || '');
  }

  function conditionLabel(cond) {
    const cm = conditionMeta(cond);
    return (cm && cm.label) ? cm.label : (cond || '');
  }

  function outcomeLabel(outcome) {
    const v = outcome || 'completed';
    const o = (config.end_outcomes || []).find((x) => x.value === v);
    return o ? o.label : v;
  }

  function staffAdminIds(data) {
    let ids = data.staff_admin_ids;
    if (!Array.isArray(ids)) {
      ids = [];
    }
    if (ids.length === 0 && String(data.staff_recipients || '').trim()) {
      ids = staffAdminIdsFromLegacyEmails(data.staff_recipients);
    }
    return ids.map(String);
  }

  function staffAdminIdsFromLegacyEmails(raw) {
    const emails = String(raw || '').split(/[,;]+/).map((s) => s.trim().toLowerCase()).filter(Boolean);
    const out = [];
    (config.staff_admins || []).forEach((opt) => {
      const em = String(opt.email || '').trim().toLowerCase();
      if (em && emails.includes(em)) {
        out.push(String(opt.value));
      }
    });
    return out;
  }

  function staffAdminSummaryText(ids) {
    const list = (ids || []).map(String);
    if (!list.length) {
      return 'Не выбрано';
    }
    const names = list.map((id) => {
      const opt = (config.staff_admins || []).find((o) => String(o.value) === id);
      return opt ? (opt.name || opt.email) : id;
    });
    if (names.length === 1) {
      return names[0];
    }
    if (names.length === 2) {
      return names.join(', ');
    }
    return names.length + ' выбрано: ' + names.slice(0, 2).join(', ') + '…';
  }

  function bindStaffAdminMultiselect(dfId, d) {
    const root = propsPanel.querySelector('[data-staff-multiselect]');
    if (!root) {
      return;
    }
    const trigger = root.querySelector('.automation-staff-multiselect__trigger');
    const panel = root.querySelector('.automation-staff-multiselect__panel');
    const labelEl = root.querySelector('.automation-staff-multiselect__label');
    if (!trigger || !panel || !labelEl) {
      return;
    }

    function syncLabel() {
      labelEl.textContent = staffAdminSummaryText(staffAdminIds(d));
    }

    const propsWrap = root.closest('.automation-flow-props-wrap');

    function setOpen(open) {
      root.classList.toggle('is-open', open);
      trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
      panel.hidden = !open;
      if (propsWrap) {
        propsWrap.classList.toggle('automation-props--staff-open', open);
      }
    }

    trigger.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      setOpen(!root.classList.contains('is-open'));
    });

    panel.addEventListener('click', (e) => {
      e.stopPropagation();
    });

    root.querySelectorAll('[data-staff-admin]').forEach((el) => {
      el.addEventListener('change', () => {
        pushHistory();
        const picked = [];
        root.querySelectorAll('[data-staff-admin]:checked').forEach((chk) => {
          picked.push(chk.getAttribute('data-staff-admin'));
        });
        d.staff_admin_ids = picked;
        d.staff_recipients = '';
        editor.updateNodeDataFromId(dfId, d);
        updateNodeHtml(dfId);
        syncLabel();
        scheduleHistory();
      });
    });

    syncLabel();
  }

  function ensureStaffAdminIdsFromLegacy(d) {
    if (!Array.isArray(d.staff_admin_ids)) {
      d.staff_admin_ids = [];
    }
    if (d.staff_admin_ids.length === 0 && String(d.staff_recipients || '').trim()) {
      d.staff_admin_ids = staffAdminIdsFromLegacyEmails(d.staff_recipients);
      if (d.staff_admin_ids.length) {
        d.staff_recipients = '';
      }
    }
  }

  function defaultStaffNotifyBody() {
    return '{{student_name}}\n'
      + '{{student_email}}\n'
      + '{{course_slug}}\n'
      + '{{automation_title}}\n'
      + '{{admin_student_url}}';
  }

  function templateEditUrl(id) {
    const t = (config.templates || []).find((x) => x.value === id);
    return t && t.edit_url ? t.edit_url : (id ? '/admin/emails/' + id + '/edit' : '');
  }

  function secondsToDhm(total) {
    let s = Math.max(0, parseInt(total, 10) || 0);
    const days = Math.floor(s / 86400);
    s -= days * 86400;
    const hours = Math.floor(s / 3600);
    s -= hours * 3600;
    const minutes = Math.floor(s / 60);
    return { days, hours, minutes };
  }

  function dhmToSeconds(days, hours, minutes) {
    return (
      (Math.max(0, parseInt(days, 10) || 0) * 86400)
      + (Math.max(0, parseInt(hours, 10) || 0) * 3600)
      + (Math.max(0, parseInt(minutes, 10) || 0) * 60)
    );
  }

  function formatDelay(sec) {
    const { days, hours, minutes } = secondsToDhm(sec);
    const parts = [];
    if (days) {
      parts.push(days + ' д');
    }
    if (hours) {
      parts.push(hours + ' ч');
    }
    if (minutes || parts.length === 0) {
      parts.push(minutes + ' мин');
    }
    return parts.join(' ');
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function outputsForType(type) {
    const item = (config.palette || []).find((p) => p.id === type);
    return item ? item.outputs : 1;
  }

  function inputsForType(type) {
    return type === 'trigger' ? 0 : 1;
  }

  function registerNode(dfId, nodeKey, data) {
    dfIdToKey.set(String(dfId), nodeKey);
    keyToDfId.set(nodeKey, String(dfId));
  }

  function unregisterNode(dfId) {
    const key = dfIdToKey.get(String(dfId));
    if (key) {
      keyToDfId.delete(key);
    }
    dfIdToKey.delete(String(dfId));
  }

  function addNodeToCanvas(type, nodeKey, data, posX, posY) {
    const outputs = outputsForType(type);
    const inputs = inputsForType(type);
    const cls = nodeCssClass(type);
    const dfId = editor.addNode(
      type,
      inputs,
      outputs,
      posX,
      posY,
      cls,
      data,
      nodeHtml(data)
    );
    registerNode(dfId, nodeKey, data);
    window.requestAnimationFrame(function () {
      applyNodeStats(dfId);
    });
    return dfId;
  }

  function updateRemoveConnBtn() {
    if (removeConnBtn) {
      removeConnBtn.disabled = !editor.connection_selected;
    }
  }

  function removeSelectedConnection() {
    if (!editor.connection_selected) {
      return;
    }
    pushHistory();
    editor.removeConnection();
    editor.connection_selected = null;
    updateRemoveConnBtn();
    if (propsPanel) {
      propsPanel.innerHTML = '<p class="field-hint">Выберите блок на схеме.</p>';
    }
    syncJsonField();
  }

  const LAYOUT_ROW_GAP = 32;
  const LAYOUT_FALLBACK_NODE_H = 150;
  const LAYOUT_MIN_NODE_H = 56;
  const LAYOUT_COL_W = 248;
  const LAYOUT_ORIGIN_Y = 36;
  const LAYOUT_CENTER_X = 340;

  function nodeHeightForLayout(key) {
    const dfId = keyToDfId.get(key);
    if (!dfId) {
      return LAYOUT_FALLBACK_NODE_H;
    }
    const el = document.getElementById('node-' + dfId);
    if (!el) {
      return LAYOUT_FALLBACK_NODE_H;
    }
    const h = Math.ceil(el.offsetHeight || el.getBoundingClientRect().height || 0);
    if (h > 0) {
      return Math.max(h, LAYOUT_MIN_NODE_H);
    }
    return LAYOUT_FALLBACK_NODE_H;
  }

  function computeTopDownPositions(nodes, edges) {
    const positions = new Map();
    const keys = Object.keys(nodes || {});
    if (keys.length === 0) {
      return positions;
    }
    const edgeList = Array.isArray(edges) ? edges : [];
    const startId = nodes.start ? 'start' : keys[0];
    const depth = new Map();
    const visitOrder = [];
    const seen = new Set();
    const queue = [[startId, 0]];
    depth.set(startId, 0);
    while (queue.length > 0) {
      const item = queue.shift();
      const id = item[0];
      const d = item[1];
      if (seen.has(id)) {
        continue;
      }
      seen.add(id);
      visitOrder.push(id);
      edgeList.forEach((edge) => {
        if (edge.from !== id) {
          return;
        }
        const next = edge.to;
        if (!next || !nodes[next]) {
          return;
        }
        const nd = d + 1;
        if (!depth.has(next) || depth.get(next) > nd) {
          depth.set(next, nd);
        }
        queue.push([next, nd]);
      });
    }
    keys.forEach((k) => {
      if (!depth.has(k)) {
        depth.set(k, 0);
        visitOrder.push(k);
      }
    });
    const byDepth = {};
    keys.forEach((k) => {
      const lv = depth.get(k) || 0;
      if (!byDepth[lv]) {
        byDepth[lv] = [];
      }
      byDepth[lv].push(k);
    });
    const orderIndex = new Map();
    visitOrder.forEach((id, i) => orderIndex.set(id, i));
    const levelNums = Object.keys(byDepth).map((lv) => parseInt(lv, 10)).sort((a, b) => a - b);
    const levelY = new Map();
    let yCursor = LAYOUT_ORIGIN_Y;
    levelNums.forEach((lv) => {
      levelY.set(lv, yCursor);
      let rowMaxH = LAYOUT_MIN_NODE_H;
      (byDepth[lv] || []).forEach((key) => {
        rowMaxH = Math.max(rowMaxH, nodeHeightForLayout(key));
      });
      yCursor += rowMaxH + LAYOUT_ROW_GAP;
    });
    levelNums.forEach((lv) => {
      const row = (byDepth[lv] || []).sort((a, b) => (orderIndex.get(a) || 0) - (orderIndex.get(b) || 0));
      const count = row.length;
      const y = levelY.get(lv) ?? LAYOUT_ORIGIN_Y;
      row.forEach((key, index) => {
        const x = Math.round(LAYOUT_CENTER_X + (index - (count - 1) / 2) * LAYOUT_COL_W);
        positions.set(key, { x, y });
      });
    });
    return positions;
  }

  function applyNodePositions(positions) {
    positions.forEach((pos, key) => {
      const dfId = keyToDfId.get(key);
      if (!dfId) {
        return;
      }
      const el = document.getElementById('node-' + dfId);
      if (el) {
        el.style.left = pos.x + 'px';
        el.style.top = pos.y + 'px';
      }
      if (editor.drawflow?.drawflow?.Home?.data?.[dfId]) {
        editor.drawflow.drawflow.Home.data[dfId].pos_x = pos.x;
        editor.drawflow.drawflow.Home.data[dfId].pos_y = pos.y;
      }
      editor.updateConnectionNodes('node-' + dfId);
    });
  }

  function layoutTopDownFromDef(def) {
    const positions = computeTopDownPositions(def.nodes || {}, def.edges || []);
    applyNodePositions(positions);
  }

  function runLayoutAfterPaint(def) {
    window.requestAnimationFrame(function () {
      layoutTopDownFromDef(def);
    });
  }

  function autoLayout() {
    pushHistory();
    const def = exportDefinition();
    window.requestAnimationFrame(function () {
      layoutTopDownFromDef(def);
      syncJsonField();
    });
  }

  function updateFullscreenButton() {
    if (fsBtn) {
      fsBtn.textContent = fullscreenOn ? 'Выйти из полноэкранного' : 'На весь экран';
    }
  }

  function restoreShellFromPortal() {
    if (!shell) {
      return;
    }
    shell.classList.remove('is-fullscreen');
    document.body.classList.remove('automation-flow-body-lock');
    const ph = document.getElementById('automation-flow-fs-placeholder');
    if (ph && ph.parentNode) {
      ph.parentNode.insertBefore(shell, ph);
      ph.remove();
    }
    fsPlaceholder = null;
  }

  function syncInlineJsonFromField() {
    if (jsonInline && jsonField) {
      jsonInline.value = jsonField.value;
    }
  }

  function jsonSourceForReload() {
    if (fullscreenOn && jsonInline && jsonDrawer && jsonDrawer.open) {
      return jsonInline;
    }
    if (fullscreenOn && jsonInline && String(jsonInline.value || '').trim() !== '') {
      return jsonInline;
    }
    return jsonField;
  }

  function refreshCanvasAfterRebuild() {
    window.requestAnimationFrame(function () {
      if (typeof editor.zoom_refresh === 'function') {
        editor.zoom_refresh();
      }
      const home = editor.drawflow?.drawflow?.Home?.data || {};
      Object.keys(home).forEach(function (dfId) {
        editor.updateConnectionNodes('node-' + dfId);
      });
      focusStartNode();
      updateZoomUi();
      applyAllNodeStats();
    });
  }

  function enterFullscreenLayout() {
    if (!shell || fullscreenOn) {
      return;
    }
    syncInlineJsonFromField();
    fsPlaceholder = document.createElement('div');
    fsPlaceholder.id = 'automation-flow-fs-placeholder';
    shell.parentNode.insertBefore(fsPlaceholder, shell);
    document.body.appendChild(shell);
    shell.classList.add('is-fullscreen');
    document.body.classList.add('automation-flow-body-lock');
    fullscreenOn = true;
    updateFullscreenButton();
  }

  function requestBrowserFullscreen() {
    if (!shell) {
      return;
    }
    const req = shell.requestFullscreen
      || shell.webkitRequestFullscreen
      || shell.msRequestFullscreen;
    if (req) {
      Promise.resolve(req.call(shell)).catch(function () {
        /* CSS overlay still covers the viewport */
      });
    }
  }

  function enterFullscreen(requestBrowser) {
    enterFullscreenLayout();
    if (requestBrowser !== false) {
      requestBrowserFullscreen();
    }
    window.setTimeout(function () {
      canvas.focus();
    }, 100);
  }

  function exitFullscreen() {
    if (!shell || !fullscreenOn) {
      return;
    }
    const doc = document;
    const fsEl = doc.fullscreenElement || doc.webkitFullscreenElement || doc.msFullscreenElement;
    if (fsEl === shell) {
      const exit = doc.exitFullscreen || doc.webkitExitFullscreen || doc.msExitFullscreen;
      if (exit) {
        Promise.resolve(exit.call(doc)).catch(function () {
          restoreShellFromPortal();
          fullscreenOn = false;
          updateFullscreenButton();
        });
        return;
      }
    }
    restoreShellFromPortal();
    fullscreenOn = false;
    updateFullscreenButton();
  }

  function toggleFullscreen() {
    if (fullscreenOn) {
      exitFullscreen();
    } else {
      enterFullscreen();
    }
  }

  document.addEventListener('fullscreenchange', onBrowserFullscreenEnd);
  document.addEventListener('webkitfullscreenchange', onBrowserFullscreenEnd);

  function markFullscreenRetainForSubmit() {
    if (!fullscreenOn) {
      return;
    }
    retainFullscreenLayout = true;
    try {
      sessionStorage.setItem(FS_RESTORE_KEY, '1');
    } catch (err) {
      // ignore
    }
  }

  function onBrowserFullscreenEnd() {
    const doc = document;
    const fsEl = doc.fullscreenElement || doc.webkitFullscreenElement || doc.msFullscreenElement;
    if (!fsEl && fullscreenOn) {
      if (retainFullscreenLayout) {
        retainFullscreenLayout = false;
        return;
      }
      restoreShellFromPortal();
      fullscreenOn = false;
      updateFullscreenButton();
    }
  }

  function removeSelectedNode() {
    if (!selectedDfId) {
      return;
    }
    removeNodeWithConfirm(selectedDfId);
  }

  function buildFromDefinition(def, options) {
    const opts = options || {};
    editor.clear();
    dfIdToKey.clear();
    keyToDfId.clear();

    const nodes = def.nodes || {};
    const edges = def.edges || [];
    const useVisual = def.visual && def.visual.drawflow && !opts.ignoreVisual;

    if (useVisual) {
      editor.import(def.visual.drawflow);
      reconcileNodeIds(nodes);
      rebuildMapsFromCanvas();
      if (opts.relayoutTopDown) {
        runLayoutAfterPaint(def);
      }
      reconcileTriggerEntrySettings();
      refreshCanvasAfterRebuild();
      return;
    }

    const keys = Object.keys(nodes);
    const positions = computeTopDownPositions(nodes, edges);
    keys.forEach((key, index) => {
      const node = nodes[key];
      const type = node.type || 'step';
      const data = Object.assign({ node_id: key, type, label: node.label || key }, node);
      const pos = positions.get(key) || { x: LAYOUT_CENTER_X, y: LAYOUT_ORIGIN_Y + index * LAYOUT_FALLBACK_NODE_H };
      addNodeToCanvas(type, key, data, pos.x, pos.y);
      if (index === 0) {
        selectedDfId = String(editor.nodeId - 1);
      }
    });

    edges.forEach((edge) => {
      const fromKey = edge.from;
      const toKey = edge.to;
      const fromDf = keyToDfId.get(fromKey);
      const toDf = keyToDfId.get(toKey);
      if (!fromDf || !toDf) {
        return;
      }
      const fromNode = nodes[fromKey];
      let outputClass = 'output_1';
      if (fromNode && fromNode.type === 'condition') {
        outputClass = edge.branch === 'yes' ? 'output_1' : 'output_2';
      }
      editor.addConnection(fromDf, toDf, outputClass, 'input_1');
    });
    rebuildMapsFromCanvas();
    runLayoutAfterPaint(def);
    refreshCanvasAfterRebuild();
    reconcileTriggerEntrySettings();
  }

  function reconcileNodeIds(defNodes) {
    const home = editor.export().drawflow?.Home?.data || {};
    const defKeys = Object.keys(defNodes);
    const used = new Set();
    Object.keys(home).forEach((dfId) => {
      const n = home[dfId];
      let key = n.data?.node_id;
      if (key && defNodes[key]) {
        used.add(key);
        return;
      }
      const byType = defKeys.filter((k) => {
        const t = defNodes[k]?.type;
        return t && t === n.name && !used.has(k);
      });
      if (byType.length === 1) {
        key = byType[0];
      } else if (!key || key === n.name) {
        key = 'node_' + dfId;
      }
      n.data = Object.assign({}, n.data, { node_id: key, type: n.data?.type || n.name });
      used.add(key);
      editor.updateNodeDataFromId(dfId, n.data);
    });
  }

  function exportDefinition() {
    const exported = editor.export();
    const home = exported.drawflow?.Home?.data || {};
    const nodes = {};
    const edges = [];

    Object.keys(home).forEach((dfId) => {
      const n = home[dfId];
      const key = n.data?.node_id || ('node_' + dfId);
      const copy = Object.assign({}, n.data);
      delete copy.node_id;
      copy.pos = { x: n.pos_x, y: n.pos_y };
      nodes[key] = copy;
    });

    Object.keys(home).forEach((dfId) => {
      const n = home[dfId];
      const fromKey = n.data?.node_id;
      if (!fromKey) {
        return;
      }
      const outputs = n.outputs || {};
      Object.keys(outputs).forEach((outName) => {
        const branch = branchFromOutput(n.data?.type, outName);
        (outputs[outName].connections || []).forEach((conn) => {
          const target = home[conn.node];
          if (!target || !target.data?.node_id) {
            return;
          }
          const edge = { from: fromKey, to: target.data.node_id };
          if (branch) {
            edge.branch = branch;
          }
          edges.push(edge);
        });
      });
    });

    const trig = findTriggerOnCanvas();
    const metaCourse = trig ? String(trig.data.process_course_slug || '').trim() : '';
    const meta = Object.assign({}, definition.meta || {}, {
      course_slug: metaCourse || processCourseSlug(),
      entry_mode: trig ? String(trig.data.entry_mode || '') : (config.automation?.entry_mode || ''),
    });
    return {
      version: definition.version || 1,
      meta,
      nodes,
      edges,
      visual: { drawflow: exported },
    };
  }

  function branchFromOutput(type, outputName) {
    if (type !== 'condition') {
      return 'next';
    }
    if (outputName === 'output_1') {
      return 'yes';
    }
    if (outputName === 'output_2') {
      return 'no';
    }
    return 'next';
  }

  function updateNodeHtml(dfId) {
    const id = normalizeDfId(dfId);
    const n = editor.getNodeFromId(id);
    if (!n) {
      return;
    }
    const el = document.querySelector('#node-' + id + ' .drawflow_content_node');
    if (el) {
      el.innerHTML = nodeHtml(n.data);
    }
    applyNodeStats(id);
    if (selectedDfId && normalizeDfId(selectedDfId) === id) {
      syncNodeDeleteButton();
    }
  }

  function bindPropHandlers(dfId, d) {
    let propHistorySaved = false;
    function ensurePropHistory() {
      if (!propHistorySaved) {
        pushHistory();
        propHistorySaved = true;
      }
    }

    propsPanel.querySelectorAll('[data-prop]').forEach((el) => {
      const handler = () => {
        const key = el.getAttribute('data-prop');
        if (key === 'seconds') {
          return;
        }
        ensurePropHistory();
        if (el.type === 'checkbox') {
          d[key] = el.checked;
        } else if (el.type === 'number') {
          d[key] = parseInt(el.value, 10) || 0;
        } else {
          d[key] = el.value;
        }
        editor.updateNodeDataFromId(dfId, d);
        updateNodeHtml(dfId);
        scheduleHistory();
        if (key === 'template' || key === 'condition' || key === 'course_slug' || key === 'staff_recipients'
          || key === 'entry_mode' || key === 'process_course_slug') {
          if (key === 'entry_mode' || key === 'process_course_slug') {
            syncAutomationFormFromTrigger();
          }
          renderProps(dfId);
        }
      };
      el.addEventListener('change', handler);
      if (el.tagName === 'INPUT' && el.type === 'number') {
        el.addEventListener('input', handler);
      }
      if (el.tagName === 'TEXTAREA') {
        el.addEventListener('input', handler);
      }
    });

    const dhmRow = propsPanel.querySelector('.automation-dhm-row');
    if (dhmRow) {
      dhmRow.querySelectorAll('[data-dhm]').forEach((el) => {
        el.addEventListener('input', () => {
          ensurePropHistory();
          const days = dhmRow.querySelector('[data-dhm="days"]')?.value;
          const hours = dhmRow.querySelector('[data-dhm="hours"]')?.value;
          const minutes = dhmRow.querySelector('[data-dhm="minutes"]')?.value;
          d.seconds = dhmToSeconds(days, hours, minutes);
          editor.updateNodeDataFromId(dfId, d);
          updateNodeHtml(dfId);
          const hint = propsPanel.querySelector('.automation-dhm-total');
          if (hint) {
            hint.textContent = formatDelay(d.seconds);
          }
          scheduleHistory();
        });
      });
    }
  }

  function courseSelectOptions() {
    return Array.isArray(config.courses) ? config.courses : [];
  }

  function courseLabelForSlug(slug) {
    const s = String(slug || '').trim();
    if (!s) {
      return '';
    }
    const c = courseSelectOptions().find((x) => String(x.value) === s);
    return c ? (c.label || s) : s;
  }

  function entryModeLabel(mode) {
    const m = String(mode || '').trim();
    const o = (config.entry_modes || []).find((x) => x.value === m);
    return o ? (o.label || m) : m;
  }

  function findTriggerOnCanvas() {
    const home = editor.export().drawflow?.Home?.data || {};
    let found = null;
    Object.keys(home).forEach((dfId) => {
      const n = home[dfId];
      const type = n.data?.type || n.name;
      const key = n.data?.node_id;
      if (type === 'trigger' || key === 'start') {
        found = { dfId: normalizeDfId(dfId), data: n.data };
      }
    });
    return found;
  }

  function processCourseSlug() {
    const trig = findTriggerOnCanvas();
    if (trig?.data) {
      const s = String(trig.data.process_course_slug ?? '').trim();
      if (s) {
        return s;
      }
    }
    const am = config.automation || {};
    return String(am.course_slug || initialProcessCourseSlug).trim() || initialProcessCourseSlug;
  }

  function reconcileTriggerEntrySettings() {
    const trig = findTriggerOnCanvas();
    if (!trig) {
      return;
    }
    const d = trig.data;
    const am = config.automation || {};
    const defStart = (definition.nodes && (definition.nodes.start || definition.nodes[d.node_id])) || {};
    if (!d.entry_mode) {
      d.entry_mode = defStart.entry_mode || am.entry_mode || 'demo_grant';
    }
    if (d.process_course_slug === undefined || d.process_course_slug === null || d.process_course_slug === '') {
      d.process_course_slug = defStart.process_course_slug
        ?? defStart.course_slug
        ?? am.course_slug
        ?? initialProcessCourseSlug;
    }
    d.process_course_slug = String(d.process_course_slug || '').trim();
    editor.updateNodeDataFromId(trig.dfId, d);
    updateNodeHtml(trig.dfId);
    syncAutomationFormFromTrigger();
  }

  function syncAutomationFormFromTrigger() {
    const trig = findTriggerOnCanvas();
    const modeEl = document.getElementById('automation-entry-mode');
    const courseEl = document.getElementById('automation-settings-course');
    const hintEl = document.getElementById('automation-entry-settings-hint');
    if (!trig) {
      return;
    }
    const mode = String(trig.data.entry_mode || 'demo_grant');
    const slug = String(trig.data.process_course_slug || '').trim();
    if (modeEl) {
      modeEl.value = mode;
    }
    if (courseEl) {
      courseEl.value = slug;
    }
    if (config.automation) {
      config.automation.entry_mode = mode;
      config.automation.course_slug = slug;
      config.automation.entry_mode_label = entryModeLabel(mode);
    }
    if (hintEl) {
      const modeText = entryModeLabel(mode);
      const courseText = slug ? courseLabelForSlug(slug) : 'не привязан';
      hintEl.innerHTML = 'С блока <strong>«Старт»</strong>: ' + escapeHtml(modeText) + ' · ' + escapeHtml(courseText);
    }
  }

  function conditionMeta(condition) {
    return (config.conditions || []).find((c) => c.value === condition);
  }

  function conditionNeedsCourseSlug(condition) {
    const m = conditionMeta(condition);
    if (m && typeof m.needs_course === 'boolean') {
      return m.needs_course;
    }
    return condition === 'has_paid_course' || condition === 'demo_lesson_opened' || condition === 'has_active_demo';
  }

  function paletteHint(type) {
    const p = (config.palette || []).find((item) => item.id === type);
    return p && p.description ? p.description : '';
  }

  function templateKind(templateId) {
    const t = (config.templates || []).find((x) => x.value === templateId);
    return t && t.kind ? t.kind : 'transactional';
  }

  function courseSlugFieldHtml(currentValue, label, opts) {
    const allowEmpty = !!(opts && opts.allowEmpty);
    const prop = (opts && opts.prop) ? String(opts.prop) : 'course_slug';
    const val = String(currentValue !== undefined && currentValue !== null ? currentValue : processCourseSlug()).trim();
    const fieldLabel = label || 'Курс';
    const courses = courseSelectOptions();
    if (courses.length === 0) {
      return (
        '<label class="field"><span class="field-label">' + escapeHtml(fieldLabel) + '</span>'
        + '<input type="text" data-prop="' + escapeHtml(prop) + '" value="' + escapeHtml(val) + '" pattern="[a-z0-9\\-]+" placeholder="slug курса"></label>'
      );
    }
    const known = new Set();
    let html = '<label class="field"><span class="field-label">' + escapeHtml(fieldLabel) + '</span><select data-prop="' + escapeHtml(prop) + '">';
    if (allowEmpty) {
      html += '<option value=""' + (val === '' ? ' selected' : '') + '>— не привязан —</option>';
    }
    courses.forEach((c) => {
      const v = String(c.value || '');
      if (!v) {
        return;
      }
      known.add(v);
      html += '<option value="' + escapeHtml(v) + '"' + (v === val ? ' selected' : '') + '>' + escapeHtml(c.label || v) + '</option>';
    });
    if (val && !known.has(val)) {
      html += '<option value="' + escapeHtml(val) + '" selected>' + escapeHtml(val) + ' (в схеме)</option>';
    }
    html += '</select></label>';
    return html;
  }

  function renderProps(dfId) {
    if (!propsPanel) {
      return;
    }
    const id = normalizeDfId(dfId);
    const n = editor.getNodeFromId(id);
    if (!n) {
      propsPanel.innerHTML = '<p class="field-hint">Выберите блок на схеме.</p>';
      return;
    }
    const d = n.data;
    let html = '<h3 class="automation-props-title">' + escapeHtml(d.label || d.type) + '</h3>';
    html += '<p class="field-hint">ID: <code>' + escapeHtml(d.node_id || id) + '</code></p>';

    html += '<label class="field"><span class="field-label">Подпись на схеме</span><input type="text" data-prop="label" value="' + escapeHtml(d.label || '') + '"></label>';

    const help = paletteHint(d.type);
    if (help) {
      html += '<p class="automation-props-help field-hint">' + escapeHtml(help) + '</p>';
    }

    if (d.type === 'trigger') {
      const curMode = d.entry_mode || (config.automation && config.automation.entry_mode) || 'demo_grant';
      html += '<label class="field"><span class="field-label">Тип входа в процесс</span><select data-prop="entry_mode">';
      (config.entry_modes || []).forEach((m) => {
        html += '<option value="' + escapeHtml(m.value) + '"' + (m.value === curMode ? ' selected' : '') + '>'
          + escapeHtml(m.label || m.value) + '</option>';
      });
      html += '</select></label>';
      html += '<p class="field-hint">Демо по курсу — AVO/API; вручную и после оплаты — допродажи и общие цепочки.</p>';
      html += courseSlugFieldHtml(d.process_course_slug, 'Курс процесса', {
        allowEmpty: true,
        prop: 'process_course_slug',
      });
      const needCourse = !!ENTRY_MODES_NEED_COURSE[curMode];
      html += '<p class="field-hint" id="automation-trigger-course-hint">' + (needCourse
        ? 'Для этого типа входа курс обязателен (сохраняется slug).'
        : 'Необязательно — контекст для писем и условий в схеме.') + '</p>';
    }

    if (d.type === 'delay') {
      const parts = secondsToDhm(d.seconds);
      html += '<div class="field"><span class="field-label">Ожидание</span>';
      html += '<div class="automation-dhm-row">';
      html += '<label class="automation-dhm-field"><span>Дни</span><input type="number" min="0" data-dhm="days" value="' + parts.days + '"></label>';
      html += '<label class="automation-dhm-field"><span>Часы</span><input type="number" min="0" max="23" data-dhm="hours" value="' + parts.hours + '"></label>';
      html += '<label class="automation-dhm-field"><span>Минуты</span><input type="number" min="0" max="59" data-dhm="minutes" value="' + parts.minutes + '"></label>';
      html += '</div>';
      html += '<p class="field-hint automation-dhm-total">' + escapeHtml(formatDelay(d.seconds)) + '</p>';
      html += '<p class="field-hint">Обработка по cron (run-email-automations). Ученик «ждёт» на этом шаге до наступления времени.</p></div>';
    }
    if (d.type === 'send_template') {
      html += '<label class="field"><span class="field-label">Шаблон письма</span><select data-prop="template">';
      (config.templates || []).forEach((t) => {
        const kindTag = t.kind === 'marketing' ? ' [маркетинг]' : '';
        html += '<option value="' + escapeHtml(t.value) + '"' + (t.value === d.template ? ' selected' : '') + '>' + escapeHtml((t.label || t.value) + kindTag) + '</option>';
      });
      html += '</select></label>';
      const kind = templateKind(d.template);
      html += '<p class="field-hint">' + (kind === 'marketing'
        ? 'Маркетинг: отписка, List-Unsubscribe, промокод cross-sell из config.'
        : 'Транзакционное: напоминания и demo/sale из AVO-воронки.') + '</p>';
      const editUrl = templateEditUrl(d.template);
      if (editUrl) {
        html += '<p><a class="btn btn-primary btn-sm" href="' + escapeHtml(editUrl) + '" target="_blank" rel="noopener">Редактировать письмо</a></p>';
      }
      html += courseSlugFieldHtml(d.course_slug, 'Курс в письме');
      html += '<p class="field-hint">Slug для buy_url, course_title и условий. Пустой — контекст run / настройки процесса.</p>';
      html += '<label class="field field-checkbox"><input type="checkbox" data-prop="skip_if_paid_course" value="1"'
        + (d.skip_if_paid_course ? ' checked' : '') + '><span>Не слать, если этот курс уже куплен</span></label>';
      if (templateNeedsCourseSlug(d.template)) {
        html += '<p class="field-hint"><strong>Cross-sell:</strong> для этого шаблона курс обязателен.</p>';
      }
    }
    if (d.type === 'condition') {
      html += '<label class="field"><span class="field-label">Условие</span><select data-prop="condition">';
      (config.conditions || []).forEach((c) => {
        html += '<option value="' + escapeHtml(c.value) + '"' + (c.value === d.condition ? ' selected' : '') + '>' + escapeHtml(c.label || c.value) + '</option>';
      });
      html += '</select></label>';
      const cm = conditionMeta(d.condition);
      if (cm && cm.hint) {
        html += '<p class="field-hint">' + escapeHtml(cm.hint) + '</p>';
      }
      if (conditionNeedsCourseSlug(d.condition)) {
        html += courseSlugFieldHtml(d.course_slug, 'Курс для условия');
      }
      html += '<p class="field-hint">Верхний кружок — «да», нижний — «нет».</p>';
    }
    if (d.type === 'revoke_demo') {
      html += '<div class="alert alert-warning" style="margin:12px 0">Блок устарел: демо снимается по таймеру курса (demo_hours). Удалите блок и соедините линию напрямую.</div>';
    }
    if (d.type === 'grant_demo') {
      html += courseSlugFieldHtml(d.course_slug, 'Курс демо');
      html += '<label class="field field-checkbox"><input type="checkbox" data-prop="skip_if_paid" value="1"'
        + (d.skip_if_paid !== false ? ' checked' : '') + '><span>Пропустить, если курс уже куплен</span></label>';
      html += '<label class="field field-checkbox"><input type="checkbox" data-prop="skip_if_demo_active" value="1"'
        + (d.skip_if_demo_active !== false ? ' checked' : '') + '><span>Пропустить, если демо уже активно</span></label>';
      html += '<p class="field-hint">Письмо demo уходит только при новой выдаче. Срок демо — в карточке курса (demo_hours), отзыв не нужен.</p>';
      if (config.demo_email_edit_url) {
        html += '<p><a class="btn btn-ghost btn-sm" href="' + escapeHtml(config.demo_email_edit_url) + '" target="_blank" rel="noopener">Шаблон письма demo</a></p>';
      }
    }
    if (d.type === 'end') {
      html += '<label class="field"><span class="field-label">Тип завершения (статистика)</span><select data-prop="outcome">';
      const outcomes = config.end_outcomes || [{ value: 'completed', label: 'Обычное завершение' }];
      const cur = d.outcome || 'completed';
      outcomes.forEach((o) => {
        html += '<option value="' + escapeHtml(o.value) + '"' + (o.value === cur ? ' selected' : '') + '>' + escapeHtml(o.label) + '</option>';
      });
      html += '</select></label>';
      html += '<p class="field-hint">Пишется в step events (detail=outcome=…). На логику веток не влияет.</p>';
    }
    if (d.type === 'notify_staff') {
      const hadLegacyRecipients = String(d.staff_recipients || '').trim();
      ensureStaffAdminIdsFromLegacy(d);
      if (hadLegacyRecipients && Array.isArray(d.staff_admin_ids) && d.staff_admin_ids.length) {
        editor.updateNodeDataFromId(id, d);
      }
      const defaultEmail = String(config.staff_notify_default_email || '').trim();
      const selected = new Set(staffAdminIds(d));
      const admins = config.staff_admins || [];
      html += '<div class="field"><span class="field-label">Кому из администраторов</span>';
      if (!admins.length) {
        html += '<p class="field-hint">Список пуст — добавьте админов в разделе Administrators или <code>admin_emails</code> в config.</p>';
      } else {
        const summary = staffAdminSummaryText(Array.from(selected));
        html += '<div class="automation-staff-multiselect" data-staff-multiselect>';
        html += '<button type="button" class="automation-staff-multiselect__trigger" aria-expanded="false" aria-haspopup="listbox">';
        html += '<span class="automation-staff-multiselect__label">' + escapeHtml(summary) + '</span>';
        html += '<span class="automation-staff-multiselect__chevron" aria-hidden="true"></span>';
        html += '</button>';
        html += '<div class="automation-staff-multiselect__panel" role="listbox" hidden>';
        admins.forEach((a) => {
          const val = String(a.value);
          const checked = selected.has(val) ? ' checked' : '';
          const name = String(a.name || a.email || '').trim();
          const email = String(a.email || '').trim();
          const roles = String(a.roles || '').trim();
          const tip = [email, roles].filter(Boolean).join(' · ');
          html += '<label class="automation-staff-admin-item" title="' + escapeHtml(tip) + '">';
          html += '<input type="checkbox" data-staff-admin="' + escapeHtml(val) + '" value="1"' + checked + '>';
          html += '<span class="automation-staff-admin-item__text">';
          html += '<span class="automation-staff-admin-item__name">' + escapeHtml(name) + '</span>';
          if (email && email !== name) {
            html += '<span class="automation-staff-admin-item__meta">' + escapeHtml(email) + '</span>';
          } else if (roles) {
            html += '<span class="automation-staff-admin-item__meta">' + escapeHtml(roles) + '</span>';
          }
          html += '</span></label>';
        });
        html += '</div></div>';
      }
      html += '</div>';
      html += '<p class="field-hint">Отметьте одного или нескольких. Если никого не выбрано — '
        + (defaultEmail ? 'письмо на <code>' + escapeHtml(defaultEmail) + '</code> из config' : 'только запись в cabinet.log (<code>staff_notify_email</code>)')
        + '. Нужен <code>mail.enabled</code>.</p>';
      html += '<label class="field"><span class="field-label">Тема письма</span>';
      html += '<input type="text" data-prop="notify_subject" value="' + escapeHtml(d.notify_subject || 'WWM automation: {{step_label}}') + '"></label>';
      html += '<label class="field"><span class="field-label">Текст уведомления</span>';
      html += '<textarea data-prop="notify_body" rows="8" spellcheck="false">' + escapeHtml(d.notify_body || defaultStaffNotifyBody()) + '</textarea></label>';
      const ph = (config.staff_notify_placeholders || []).join(', ');
      if (ph) {
        html += '<p class="field-hint">Подстановки: ' + escapeHtml(ph) + '</p>';
      }
    }

    html += '<div class="automation-props-actions" style="margin-top:12px;display:flex;flex-wrap:wrap;gap:8px">';
    html += '<button type="button" class="btn btn-ghost btn-sm automation-props-duplicate">Дублировать</button>';
    html += '<button type="button" class="btn btn-ghost btn-sm automation-props-delete">Удалить блок</button>';
    html += '</div>';
    propsPanel.innerHTML = html;

    bindPropHandlers(id, d);
    bindStaffAdminMultiselect(id, d);

    const dup = propsPanel.querySelector('.automation-props-duplicate');
    if (dup) {
      dup.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        pushHistory();
        const copy = JSON.parse(JSON.stringify(d));
        const newKey = slugifyKey(copy.node_id || copy.type || 'node');
        copy.node_id = newKey;
        copy.label = (copy.label || '') + ' (копия)';
        const dfId = addNodeToCanvas(
          copy.type || n.name,
          newKey,
          copy,
          (n.pos_x || 100) + 40,
          (n.pos_y || 100) + 40
        );
        selectedDfId = String(dfId);
        renderProps(selectedDfId);
        syncJsonField();
      });
    }

    const del = propsPanel.querySelector('.automation-props-delete');
    if (del) {
      del.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        removeSelectedNode();
      });
    }
  }

  if (palette) {
    (config.palette || []).forEach((item) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'automation-palette-btn ' + (item.class || '').trim();
      btn.textContent = item.short ? item.short + ' — ' + item.label : item.label;
      btn.title = item.description || item.label;
      btn.addEventListener('click', () => {
        pushHistory();
        let key = slugifyKey(item.id);
        if (item.id === 'trigger') {
          const used = new Set(definition.nodes ? Object.keys(definition.nodes) : []);
          keyToDfId.forEach((_, k) => used.add(k));
          if (!used.has('start')) {
            key = 'start';
          }
        }
        const data = defaultData(item.id, key);
        const dfId = addNodeToCanvas(item.id, key, data, 120 + Math.random() * 120, 80 + Math.random() * 120);
        selectedDfId = String(dfId);
        renderProps(selectedDfId);
        syncJsonField();
      });
      palette.appendChild(btn);
    });
  }

  function defaultData(type, nodeKey) {
    const base = { node_id: nodeKey, type, label: type };
    const pal = (config.palette || []).find((p) => p.id === type);
    if (pal) {
      base.label = pal.label;
    }
    if (type === 'delay') {
      base.seconds = 3600;
    }
    if (type === 'send_template') {
      base.template = (config.templates && config.templates[0]?.value) || 'reminder_demo_no_login';
      base.course_slug = processCourseSlug();
      base.skip_if_paid_course = false;
    }
    if (type === 'trigger') {
      base.entry_mode = (config.automation && config.automation.entry_mode) || 'demo_grant';
      base.process_course_slug = processCourseSlug();
    }
    if (type === 'notify_staff') {
      base.staff_admin_ids = [];
      base.staff_recipients = '';
      base.notify_subject = 'WWM automation: {{step_label}}';
      base.notify_body = defaultStaffNotifyBody();
    }
    if (type === 'condition') {
      base.condition = 'has_paid_any';
      base.course_slug = processCourseSlug();
    }
    if (type === 'grant_demo') {
      base.course_slug = processCourseSlug();
      base.skip_if_paid = true;
      base.skip_if_demo_active = true;
    }
    if (type === 'end') {
      base.outcome = 'completed';
    }
    return base;
  }

  root.addEventListener('keydown', (e) => {
    if (e.target.closest('input, textarea, select, [contenteditable]')) {
      return;
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 'z' && !e.shiftKey) {
      e.preventDefault();
      undo();
      return;
    }
    if (e.key === 'Escape' && fullscreenOn) {
      return;
    }
    if (e.key === 'Delete' || (e.key === 'Backspace' && !e.metaKey)) {
      if (editor.connection_selected) {
        e.preventDefault();
        removeSelectedConnection();
        return;
      }
      if (selectedDfId) {
        e.preventDefault();
        removeSelectedNode();
      }
    }
  });

  buildFromDefinition(definition);
  reconcileTriggerEntrySettings();
  updateZoomUi();
  if (jsonField) {
    syncJsonField();
  }

  function nodeLabel(id, node) {
    const label = String(node?.label || '').trim();
    return label ? label + ' (' + id + ')' : id;
  }

  function collectFlowValidationIssues(def) {
    const issues = [];
    if (!def || typeof def !== 'object') {
      return ['Не удалось собрать определение сценария.'];
    }
    const nodes = def.nodes;
    const edges = def.edges;
    if (!nodes || typeof nodes !== 'object' || Object.keys(nodes).length === 0) {
      return ['Добавьте хотя бы один блок на схему.'];
    }
    if (!Array.isArray(edges)) {
      return ['Некорректный список связей.'];
    }

    const entryIds = [];
    Object.keys(nodes).forEach((id) => {
      const node = nodes[id];
      if (!node || typeof node !== 'object') {
        issues.push('Узел «' + id + '» повреждён.');
        return;
      }
      const type = String(node.type || '');
      if (type === 'trigger' || id === 'start') {
        entryIds.push(id);
      }
    });
    if (entryIds.length === 0) {
      issues.push('Нужен блок «Старт» (Start слева, id start).');
    }

    const out = {};
    const inn = {};
    Object.keys(nodes).forEach((id) => {
      out[id] = [];
      inn[id] = [];
    });

    edges.forEach((edge, i) => {
      if (!edge || typeof edge !== 'object') {
        issues.push('Связь #' + (i + 1) + ' некорректна.');
        return;
      }
      const from = String(edge.from || '');
      const to = String(edge.to || '');
      if (!from || !to) {
        issues.push('Связь #' + (i + 1) + ': укажите from и to.');
        return;
      }
      if (!nodes[from]) {
        issues.push('Связь из неизвестного блока «' + from + '».');
        return;
      }
      if (!nodes[to]) {
        issues.push('Связь в неизвестный блок «' + to + '».');
        return;
      }
      const branch = String(edge.branch || 'next');
      out[from].push({ to, branch });
      inn[to].push({ from, branch });
    });

    let hasEnd = false;
    Object.keys(nodes).forEach((id) => {
      const node = nodes[id];
      if (!node || typeof node !== 'object') {
        return;
      }
      const type = String(node.type || '');
      const label = nodeLabel(id, node);
      const outCount = (out[id] || []).length;
      const inCount = (inn[id] || []).length;

      if (type === 'end') {
        hasEnd = true;
        if (inCount === 0) {
          issues.push('«' + label + '»: «Конец» не подключён.');
        }
        if (outCount > 0) {
          issues.push('«' + label + '»: после «Конец» не должно быть исходящих связей.');
        }
        return;
      }

      if (type === 'trigger' || id === 'start') {
        if (outCount === 0) {
          issues.push('«' + label + '»: от старта нужна связь к следующему шагу.');
        }
        return;
      }

      if (inCount === 0 && outCount === 0) {
        issues.push('«' + label + '»: блок изолирован (нет связей).');
      } else {
        if (inCount === 0) {
          issues.push('«' + label + '»: нет входящей связи.');
        }
        if (outCount === 0) {
          issues.push('«' + label + '»: нет исходящей связи (добавьте шаг или «Конец»).');
        }
      }

      if (type === 'condition') {
        const branches = (out[id] || []).map((e) => e.branch);
        if (!branches.includes('yes') || !branches.includes('no')) {
          issues.push('«' + label + '»: у условия должны быть ветки «да» и «нет».');
        }
        const cond = String(node.condition || '');
        if (!cond) {
          issues.push('«' + label + '»: выберите тип условия.');
        } else if (conditionNeedsCourseSlug(cond)) {
          const slug = String(node.course_slug || '').trim();
          if (!slug) {
            issues.push('«' + label + '»: для условия нужен курс.');
          }
        }
      }

      if (type === 'revoke_demo') {
        issues.push('«' + label + '»: блок «Отзыв демо» устарел — удалите и пересоедините ветки.');
      }

      if (type === 'send_template' && !String(node.template || '').trim()) {
        issues.push('«' + label + '»: выберите шаблон письма.');
      }
      if (type === 'send_template' && templateNeedsCourseSlug(node.template)) {
        if (!String(node.course_slug || '').trim()) {
          issues.push('«' + label + '»: для cross-sell письма укажите курс.');
        }
      }

      if (type === 'grant_demo' && !String(node.course_slug || '').trim()) {
        issues.push('«' + label + '»: укажите курс демо.');
      }

      if (type === 'trigger' || id === 'start') {
        const mode = String(node.entry_mode || '').trim() || 'demo_grant';
        const procCourse = String(node.process_course_slug ?? node.course_slug ?? '').trim();
        if (ENTRY_MODES_NEED_COURSE[mode] && !procCourse) {
          issues.push('«' + label + '»: для типа входа «' + entryModeLabel(mode) + '» укажите курс в блоке «Старт».');
        }
      }

      if (type === 'notify_staff') {
        const ids = Array.isArray(node.staff_admin_ids) ? node.staff_admin_ids : [];
        const legacy = String(node.staff_recipients || '').trim();
        if (ids.length === 0 && legacy) {
          legacy.split(/[,;\s]+/).forEach((part) => {
            const email = part.trim();
            if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
              issues.push('«' + label + '»: некорректный email в устаревшем поле получателей.');
            }
          });
        }
      }

      if (type === 'delay' && (parseInt(node.seconds, 10) || 0) < 0) {
        issues.push('«' + label + '»: укажите длительность паузы.');
      }

      if (type === 'end') {
        const outcome = String(node.outcome || 'completed');
        const allowed = (config.end_outcomes || []).map((o) => o.value);
        if (allowed.length && outcome && !allowed.includes(outcome)) {
          issues.push('«' + label + '»: выберите тип завершения.');
        }
      }
    });

    if (!hasEnd) {
      issues.push('Добавьте блок «Конец» и подключите завершающие ветки.');
    }
    if (edges.length === 0 && Object.keys(nodes).length > 1) {
      issues.push('Соедините блоки линиями.');
    }

    const seen = {};
    const queue = entryIds.slice();
    while (queue.length) {
      const id = queue.shift();
      if (!id || seen[id] || !nodes[id]) {
        continue;
      }
      seen[id] = true;
      (out[id] || []).forEach((e) => {
        if (!seen[e.to]) {
          queue.push(e.to);
        }
      });
    }
    Object.keys(nodes).forEach((id) => {
      const node = nodes[id];
      const type = String(node?.type || '');
      if (type === 'end' || type === 'trigger' || id === 'start') {
        return;
      }
      if (!seen[id]) {
        issues.push('«' + nodeLabel(id, node) + '»: не достигается от старта.');
      }
    });

    return issues.filter((item, idx, arr) => arr.indexOf(item) === idx);
  }

  function validateDefinitionClient(def) {
    const issues = collectFlowValidationIssues(def);
    if (issues.length === 0) {
      return '';
    }
    return 'Исправьте схему перед сохранением:\n\n' + issues.map((line) => '• ' + line).join('\n');
  }

  function validateAutomationEntryFromForm() {
    const modeEl = document.getElementById('automation-entry-mode');
    const courseEl = document.getElementById('automation-settings-course');
    if (!modeEl || !courseEl) {
      return '';
    }
    const mode = String(modeEl.value || 'demo_grant');
    const slug = String(courseEl.value || '').trim();
    if (ENTRY_MODES_NEED_COURSE[mode] && !slug) {
      return 'Для типа входа «' + entryModeLabel(mode) + '» выберите курс в блоке «Старт» на схеме.';
    }
    return '';
  }

  function showValidationAlert(message) {
    if (typeof wwmAdminAlert === 'function') {
      wwmAdminAlert({ title: 'Схема не готова к сохранению', message });
    }
  }

  function prepareFormSave() {
    if (!jsonField) {
      return 'Поле definition_json не найдено на странице.';
    }
    if (fullscreenOn && jsonInline && String(jsonInline.value || '').trim() !== '') {
      jsonField.value = jsonInline.value;
    }
    try {
      syncAutomationFormFromTrigger();
      syncJsonField();
      const def = JSON.parse(jsonField.value);
      const formErr = validateAutomationEntryFromForm();
      if (formErr) {
        return formErr;
      }
      return validateDefinitionClient(def);
    } catch (err) {
      return 'Ошибка при экспорте схемы в JSON. Проверьте блоки на холсте.';
    }
  }

  function syncJsonField() {
    const def = exportDefinition();
    const text = JSON.stringify(def, null, 2);
    if (jsonField) {
      jsonField.value = text;
    }
    if (jsonInline) {
      jsonInline.value = text;
    }
  }

  const saveErrorMessages = {
    csrf: 'Сессия истекла. Обновите страницу и попробуйте снова.',
    invalid_json: 'Definition JSON is not valid.',
    invalid_definition: 'В JSON должны быть nodes (с блоком start), edges и корректные связи.',
    course_required: 'Для выбранного типа входа нужен course slug.',
    not_found: 'Процесс не найден.',
  };

  function showFlowSaveToast(message) {
    if (!shell) {
      return;
    }
    let el = document.getElementById('automation-flow-save-toast');
    if (!el) {
      el = document.createElement('div');
      el.id = 'automation-flow-save-toast';
      el.className = 'alert alert-success automation-flow-save-toast';
      el.setAttribute('role', 'status');
      shell.appendChild(el);
    }
    el.textContent = message;
    el.hidden = false;
    window.clearTimeout(showFlowSaveToast._timer);
    showFlowSaveToast._timer = window.setTimeout(function () {
      el.hidden = true;
    }, 4500);
  }

  function saveAutomationInPlace() {
    if (!form) {
      return Promise.resolve(false);
    }
    const err = prepareFormSave();
    if (err) {
      showValidationAlert(err);
      return Promise.resolve(false);
    }

    const saveBtn = document.getElementById('automation-flow-save');
    const footerSave = form.querySelector('.admin-form-footer .btn-primary[type="submit"]');
    [saveBtn, footerSave].forEach(function (btn) {
      if (btn) {
        btn.disabled = true;
      }
    });

    const body = new FormData(form);
    return fetch(form.action, {
      method: 'POST',
      body,
      credentials: 'same-origin',
      headers: {
        'X-WWM-Automation-Save': '1',
        Accept: 'application/json',
      },
    })
      .then(function (res) {
        return res.json().then(function (data) {
          return { res, data };
        });
      })
      .then(function (payload) {
        const res = payload.res;
        const data = payload.data || {};
        if (!res.ok || !data.ok) {
          const code = String(data.error || '');
          const msg = String(data.detail || '').trim()
            || saveErrorMessages[code]
            || 'Не удалось сохранить. Попробуйте ещё раз.';
          showValidationAlert(msg);
          return false;
        }
        showFlowSaveToast(data.message || 'Сохранено.');
        return true;
      })
      .catch(function () {
        if (typeof wwmAdminAlert === 'function') {
          wwmAdminAlert({ title: 'Не сохранено', message: 'Сетевая ошибка при сохранении.' });
        }
        return false;
      })
      .finally(function () {
        [saveBtn, footerSave].forEach(function (btn) {
          if (btn) {
            btn.disabled = false;
          }
        });
      });
  }

  function submitAutomationForm() {
    if (!form) {
      return;
    }
    if (fullscreenOn) {
      saveAutomationInPlace();
      return;
    }
    const err = prepareFormSave();
    if (err) {
      showValidationAlert(err);
      return;
    }
    markFullscreenRetainForSubmit();
    form.requestSubmit();
  }

  window.wwmAutomationFlowPrepareSave = prepareFormSave;

  if (form) {
    form.addEventListener('submit', function (e) {
      const err = prepareFormSave();
      if (err) {
        e.preventDefault();
        showValidationAlert(err);
        return;
      }
      if (fullscreenOn) {
        e.preventDefault();
        saveAutomationInPlace();
        return;
      }
      markFullscreenRetainForSubmit();
    }, true);
  }

  const syncBtn = document.getElementById('automation-flow-sync-json');
  if (syncBtn) {
    syncBtn.addEventListener('click', function () {
      syncJsonField();
      if (fullscreenOn && jsonDrawer) {
        jsonDrawer.open = true;
      }
    });
  }

  const reloadBtn = document.getElementById('automation-flow-reload-json');
  if (reloadBtn && (jsonField || jsonInline)) {
    reloadBtn.addEventListener('click', () => {
      const applyJson = function () {
        pushHistory();
        const source = jsonSourceForReload();
        if (!source) {
          return;
        }
        try {
          const def = JSON.parse(source.value);
          delete def.visual;
          Object.assign(definition, def);
          buildFromDefinition(def, { ignoreVisual: true });
          syncJsonField();
          if (jsonField && source !== jsonField) {
            jsonField.value = source.value;
          }
        } catch (err) {
          if (typeof wwmAdminAlert === 'function') {
            wwmAdminAlert({
              title: 'Ошибка JSON',
              message: fullscreenOn
                ? 'Некорректный JSON. Откройте «JSON сценария» над схемой и проверьте синтаксис.'
                : 'Некорректный JSON. Проверьте синтаксис в поле Definition (JSON) ниже.',
            });
          }
        }
      };
      if (typeof wwmAdminConfirm === 'function') {
        wwmAdminConfirm({
          title: 'Загрузить из JSON?',
          message: 'Схема на холсте будет заменена содержимым JSON. Несохранённые правки на холсте пропадут.',
          confirmLabel: 'Загрузить',
        }).then((ok) => {
          if (ok) {
            if (fullscreenOn && jsonDrawer && !jsonDrawer.open) {
              syncInlineJsonFromField();
              jsonDrawer.open = true;
            }
            applyJson();
          }
        });
      } else {
        applyJson();
      }
    });
  }

  if (undoBtn) {
    undoBtn.addEventListener('click', undo);
  }

  if (removeConnBtn) {
    removeConnBtn.addEventListener('click', removeSelectedConnection);
  }

  if (fsBtn) {
    fsBtn.addEventListener('click', toggleFullscreen);
  }

  const flowSaveBtn = document.getElementById('automation-flow-save');
  if (flowSaveBtn) {
    flowSaveBtn.addEventListener('click', submitAutomationForm);
  }

  const zoomIn = document.getElementById('automation-flow-zoom-in');
  const zoomOut = document.getElementById('automation-flow-zoom-out');
  const zoomReset = document.getElementById('automation-flow-zoom-reset');
  const gotoStart = document.getElementById('automation-flow-goto-start');
  if (zoomIn) {
    zoomIn.addEventListener('click', () => editor.zoom_in());
  }
  if (zoomOut) {
    zoomOut.addEventListener('click', () => editor.zoom_out());
  }
  if (zoomReset) {
    zoomReset.addEventListener('click', () => {
      editor.zoom_reset();
      editor.canvas_x = 0;
      editor.canvas_y = 0;
      editor.precanvas.style.transform = 'translate(0px, 0px) scale(' + editor.zoom + ')';
      updateZoomUi();
    });
  }
  if (zoomSlider) {
    zoomSlider.addEventListener('input', () => {
      setZoomRatio(parseInt(zoomSlider.value, 10) / 100);
    });
  }
  if (gotoStart) {
    gotoStart.addEventListener('click', focusStartNode);
  }

  const layoutBtn = document.getElementById('automation-flow-layout');
  if (layoutBtn) {
    layoutBtn.addEventListener('click', autoLayout);
  }

  canvas.setAttribute('tabindex', '0');
  updateRemoveConnBtn();
  applyAllNodeStats();

  canvas.addEventListener('pointerdown', function (e) {
    if (e.target.closest('.automation-node-stat')) {
      e.stopPropagation();
    }
  }, true);
  canvas.addEventListener('mousedown', function (e) {
    if (e.target.closest('.automation-node-stat')) {
      e.stopPropagation();
    }
  }, true);
  canvas.addEventListener('click', function (e) {
    const btn = e.target.closest('.automation-node-stat');
    if (!btn) {
      return;
    }
    e.preventDefault();
    e.stopPropagation();
    openNodePeople(btn.dataset.nodeId || '', btn.dataset.kind || 'passed');
  });

  try {
    if (sessionStorage.getItem(FS_RESTORE_KEY) === '1') {
      sessionStorage.removeItem(FS_RESTORE_KEY);
      window.setTimeout(function () {
        enterFullscreen(false);
      }, 150);
    }
  } catch (err) {
    // ignore
  }
})();
