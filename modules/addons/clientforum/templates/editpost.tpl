<div class="clientforum">
  <h2>Edit Post</h2>

  {if $error}
    <div class="alert alert-danger">{$error}</div>
  {/if}

  <form method="post" action="index.php?m=clientforum&forumaction=editpost&post_id={$post->id}">
    <input type="hidden" name="csrf_token" value="{$csrfToken}">

    {if $isFirstPost}
      <div class="form-group">
        <label>Title</label>
        <input type="text" name="title" class="form-control" value="{$thread->title|escape}" maxlength="{$maxTitleLength}" required>
      </div>
    {/if}

    <div class="form-group">
      <label>Message</label>
      <textarea id="cf-editpost-body" name="body" rows="8" class="form-control" maxlength="{$maxBodyLength}" required>{$post->body|escape}</textarea>
      <div class="cf-editor-hint">Supports **bold**, *italic*, `code`, [links](https://...), lists, and &gt; quotes.</div>
    </div>

    <button type="submit" class="btn btn-primary">Save Changes</button>
    <a href="index.php?m=clientforum&forumaction=thread&thread_id={$thread->id}" class="btn btn-default">Cancel</a>
  </form>
  <script src="modules/addons/clientforum/js/editor.js?v=2.2.3"></script>
  <script>attachMarkdownEditor('cf-editpost-body');</script>
</div>
