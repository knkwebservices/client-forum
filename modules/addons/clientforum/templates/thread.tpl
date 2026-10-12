<div class="clientforum">
  <h2>{$thread->title|escape}</h2>

  {if $thread->source_ticket_id}
    <div class="cf-promoted-note">Promoted from support ticket #{$thread->source_ticket_id}</div>
  {/if}

  {if $thread->best_answer_post_id}
    <div class="cf-best-answer-banner">
      <span class="cf-label cf-label-success">Best Answer</span> This thread has an accepted answer below.
    </div>
  {/if}

  {foreach from=$posts item=post name=postloop}
    <div class="cf-post {if $post->is_staff_post}cf-post-staff{/if}{if $post->is_best_answer} cf-post-best{/if}">
      {if $post->is_best_answer}<div class="cf-best-answer-tag">✓ Best Answer</div>{/if}
      <div class="cf-post-author">
        {$post->firstname|escape} {$post->lastname|escape}
        {if $post->is_staff_post}<span class="cf-staff-badge">Staff</span>{/if}
        <span class="cf-post-karma">{$post->author_karma} pts</span>
      </div>
      <div class="cf-post-date">{$post->created_at}{if $post->edited_at} · <span class="cf-post-edited">edited {$post->edited_at}</span>{/if}</div>
      <div class="cf-post-body">{$post->body_html}</div>

      {if $post->can_edit || ($canMarkBestAnswer && !$smarty.foreach.postloop.first)}
        <div class="cf-post-actions">
          {if $post->can_edit}
            <a href="index.php?m=clientforum&forumaction=editpost&post_id={$post->id}" class="cf-link-action">Edit</a>
          {/if}
          {if $canMarkBestAnswer && !$smarty.foreach.postloop.first}
            {if $post->is_best_answer}
              <form method="post" action="index.php?m=clientforum&forumaction=unmarkbestanswer&thread_id={$thread->id}" style="display:inline;">
                <input type="hidden" name="csrf_token" value="{$bestAnswerToken}">
                <button type="submit" class="cf-link-action">Unmark as Best Answer</button>
              </form>
            {else}
              <form method="post" action="index.php?m=clientforum&forumaction=markbestanswer&thread_id={$thread->id}" style="display:inline;">
                <input type="hidden" name="csrf_token" value="{$bestAnswerToken}">
                <input type="hidden" name="post_id" value="{$post->id}">
                <button type="submit" class="cf-link-action">Mark as Best Answer</button>
              </form>
            {/if}
          {/if}
        </div>
      {/if}
    </div>
  {/foreach}

  {if $thread->is_locked}
    <div class="alert alert-warning">This thread is locked and no longer accepting replies.</div>
  {else}
    <form method="post" action="index.php?m=clientforum&forumaction=reply&thread_id={$thread->id}" class="cf-reply-form">
      <input type="hidden" name="csrf_token" value="{$csrfToken}">
      <textarea id="cf-reply-body" name="body" rows="5" class="form-control" placeholder="Write a reply... (Markdown supported)" maxlength="{$maxBodyLength}" required></textarea>
      <div class="cf-editor-hint">Supports **bold**, *italic*, `code`, [links](https://...), lists, and &gt; quotes.</div>
      <br>
      <button type="submit" class="btn btn-primary">Post Reply</button>
    </form>
    <script src="modules/addons/clientforum/js/editor.js"></script>
    <script>attachMarkdownEditor('cf-reply-body');</script>
  {/if}

  <br>
  <a href="index.php?m=clientforum&forumaction=board&board_id={$board->id}">&larr; Back to {$board->name|escape}</a>
</div>
