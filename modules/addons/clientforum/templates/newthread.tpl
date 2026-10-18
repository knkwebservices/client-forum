<div class="clientforum">
  <h2>New Thread — {$board->name|escape}</h2>

  {if $error}
    <div class="alert alert-danger">{$error}</div>
  {/if}

  <form method="post" action="index.php?m=clientforum&forumaction=newthread&board_id={$board->id}">
    <input type="hidden" name="csrf_token" value="{$csrfToken}">
    <div class="form-group">
      <label>Title</label>
      <input type="text" name="title" class="form-control" maxlength="{$maxTitleLength}" required>
    </div>
    <div class="form-group">
      <label>Message</label>
      <textarea id="cf-newthread-body" name="body" rows="8" class="form-control" maxlength="{$maxBodyLength}" required></textarea>
      <div class="cf-editor-hint">Supports **bold**, *italic*, `code`, [links](https://...), lists, and &gt; quotes.</div>
    </div>
    <button type="submit" class="btn btn-primary">Post Thread</button>
    <a href="index.php?m=clientforum&forumaction=board&board_id={$board->id}" class="btn btn-default">Cancel</a>
  </form>
  <script src="modules/addons/clientforum/js/editor.js?v=2.2.3"></script>
  <script>attachMarkdownEditor('cf-newthread-body');</script>
</div>
