/**
 * editor.js
 * Attaches a small formatting toolbar and a Write/Preview toggle to a
 * textarea. This is a convenience layer only — the toolbar just inserts
 * Markdown syntax at the cursor, and Preview renders a client-side
 * approximation of the same Markdown subset the server renders in
 * lib/Markdown.php. The *authoritative* render always happens
 * server-side at display time; this preview is for the poster's benefit
 * while writing and never gets submitted or stored — the textarea's raw
 * Markdown text is what's actually posted.
 */
(function () {
  'use strict';

  function escapeHtml(str) {
    return str
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  // Small mirror of lib/Markdown.php's subset, for preview only.
  function renderPreview(raw) {
    var text = escapeHtml(raw).replace(/\r\n/g, '\n');

    var codeBlocks = [];
    text = text.replace(/```([a-zA-Z0-9_-]*)\n([\s\S]*?)```/g, function (m, lang, code) {
      var idx = codeBlocks.length;
      codeBlocks.push('<pre><code>' + code + '</code></pre>');
      return '\u0000CODEBLOCK' + idx + '\u0000';
    });

    var inlineCode = [];
    text = text.replace(/`([^`\n]+)`/g, function (m, code) {
      var idx = inlineCode.length;
      inlineCode.push('<code>' + code + '</code>');
      return '\u0000INLINECODE' + idx + '\u0000';
    });

    text = text.replace(/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/g,
      '<a href="$2" target="_blank" rel="nofollow noopener noreferrer">$1</a>');

    text = text.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
    text = text.replace(/\*([^*\n]+)\*/g, '<em>$1</em>');
    text = text.replace(/(^|[^\w])_([^_\n]+)_(?!\w)/g, '$1<em>$2</em>');

    var lines = text.split('\n');
    var html = [];
    var inUl = false, inOl = false, inQuote = false;
    var para = [];

    function flushPara() {
      if (para.length) {
        html.push('<p>' + para.join('<br>') + '</p>');
        para = [];
      }
    }
    function closeBlocks() {
      if (inUl) { html.push('</ul>'); inUl = false; }
      if (inOl) { html.push('</ol>'); inOl = false; }
      if (inQuote) { html.push('</blockquote>'); inQuote = false; }
    }

    lines.forEach(function (line) {
      var m;
      if ((m = line.match(/^(#{1,3})\s+(.*)$/))) {
        flushPara(); closeBlocks();
        var level = m[1].length + 3;
        html.push('<h' + level + '>' + m[2] + '</h' + level + '>');
        return;
      }
      if ((m = line.match(/^[-*]\s+(.*)$/))) {
        flushPara();
        if (inOl) { html.push('</ol>'); inOl = false; }
        if (inQuote) { html.push('</blockquote>'); inQuote = false; }
        if (!inUl) { html.push('<ul>'); inUl = true; }
        html.push('<li>' + m[1] + '</li>');
        return;
      }
      if ((m = line.match(/^\d+\.\s+(.*)$/))) {
        flushPara();
        if (inUl) { html.push('</ul>'); inUl = false; }
        if (inQuote) { html.push('</blockquote>'); inQuote = false; }
        if (!inOl) { html.push('<ol>'); inOl = true; }
        html.push('<li>' + m[1] + '</li>');
        return;
      }
      if ((m = line.match(/^&gt;\s?(.*)$/))) {
        flushPara();
        if (inUl) { html.push('</ul>'); inUl = false; }
        if (inOl) { html.push('</ol>'); inOl = false; }
        if (!inQuote) { html.push('<blockquote>'); inQuote = true; }
        html.push(m[1] + '<br>');
        return;
      }
      if (line.trim() === '') {
        flushPara(); closeBlocks();
        return;
      }
      if (/^\u0000CODEBLOCK\d+\u0000$/.test(line.trim())) {
        flushPara(); closeBlocks();
        html.push(line.trim());
        return;
      }
      closeBlocks();
      para.push(line);
    });
    flushPara(); closeBlocks();

    var result = html.join('\n');
    codeBlocks.forEach(function (block, i) {
      result = result.split('\u0000CODEBLOCK' + i + '\u0000').join(block);
    });
    inlineCode.forEach(function (code, i) {
      result = result.split('\u0000INLINECODE' + i + '\u0000').join(code);
    });
    return result;
  }

  function wrapSelection(textarea, before, after) {
    after = after || before;
    var start = textarea.selectionStart;
    var end = textarea.selectionEnd;
    var value = textarea.value;
    var selected = value.slice(start, end) || 'text';
    textarea.value = value.slice(0, start) + before + selected + after + value.slice(end);
    textarea.focus();
    textarea.selectionStart = start + before.length;
    textarea.selectionEnd = start + before.length + selected.length;
  }

  function prefixLines(textarea, prefix) {
    var start = textarea.selectionStart;
    var end = textarea.selectionEnd;
    var value = textarea.value;
    var lineStart = value.lastIndexOf('\n', start - 1) + 1;
    var selected = value.slice(lineStart, end);
    var replaced = selected.split('\n').map(function (l) { return prefix + l; }).join('\n');
    textarea.value = value.slice(0, lineStart) + replaced + value.slice(end);
    textarea.focus();
  }

  window.attachMarkdownEditor = function (textareaId) {
    var textarea = document.getElementById(textareaId);
    if (!textarea) return;

    var wrapper = document.createElement('div');
    wrapper.className = 'cf-editor';
    textarea.parentNode.insertBefore(wrapper, textarea);

    var toolbar = document.createElement('div');
    toolbar.className = 'cf-editor-toolbar';

    var tabs = document.createElement('div');
    tabs.className = 'cf-editor-tabs';
    var writeTab = document.createElement('button');
    writeTab.type = 'button';
    writeTab.className = 'cf-editor-tab active';
    writeTab.textContent = 'Write';
    var previewTab = document.createElement('button');
    previewTab.type = 'button';
    previewTab.className = 'cf-editor-tab';
    previewTab.textContent = 'Preview';
    tabs.appendChild(writeTab);
    tabs.appendChild(previewTab);

    var buttons = document.createElement('div');
    buttons.className = 'cf-editor-buttons';

    function addButton(label, title, action) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'cf-editor-btn';
      btn.textContent = label;
      btn.title = title;
      btn.addEventListener('click', function () {
        action();
        preview.style.display = 'none';
        textarea.style.display = '';
        writeTab.classList.add('active');
        previewTab.classList.remove('active');
      });
      buttons.appendChild(btn);
    }

    addButton('B', 'Bold', function () { wrapSelection(textarea, '**'); });
    addButton('I', 'Italic', function () { wrapSelection(textarea, '*'); });
    addButton('</>', 'Inline code', function () { wrapSelection(textarea, '`'); });
    addButton('Link', 'Insert link', function () {
      var url = window.prompt('URL (https://...)');
      if (!url) return;
      wrapSelection(textarea, '[', '](' + url + ')');
    });
    addButton('List', 'Bullet list', function () { prefixLines(textarea, '- '); });
    addButton('Quote', 'Blockquote', function () { prefixLines(textarea, '> '); });

    toolbar.appendChild(tabs);
    toolbar.appendChild(buttons);
    wrapper.appendChild(toolbar);
    wrapper.appendChild(textarea);

    var preview = document.createElement('div');
    preview.className = 'cf-editor-preview';
    preview.style.display = 'none';
    wrapper.appendChild(preview);

    writeTab.addEventListener('click', function () {
      preview.style.display = 'none';
      textarea.style.display = '';
      writeTab.classList.add('active');
      previewTab.classList.remove('active');
    });

    previewTab.addEventListener('click', function () {
      preview.innerHTML = textarea.value.trim()
        ? renderPreview(textarea.value)
        : '<p class="cf-editor-preview-empty">Nothing to preview yet.</p>';
      preview.style.display = '';
      textarea.style.display = 'none';
      previewTab.classList.add('active');
      writeTab.classList.remove('active');
    });
  };
})();
