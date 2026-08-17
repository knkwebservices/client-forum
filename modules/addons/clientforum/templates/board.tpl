<div class="clientforum">
  <div class="cf-board-header">
    <h2>{$board->name|escape}</h2>
    <a class="btn btn-primary" href="index.php?m=clientforum&forumaction=newthread&board_id={$board->id}">New Thread</a>
  </div>

  {if $threads|@count == 0}
    <div class="alert alert-info">No threads yet — be the first to post.</div>
  {/if}

  <table class="table cf-thread-table">
    <thead>
      <tr>
        <th>Thread</th>
        <th style="width:80px;text-align:center;">Replies</th>
        <th style="width:80px;text-align:center;">Views</th>
        <th style="width:160px;">Last Activity</th>
      </tr>
    </thead>
    <tbody>
      {foreach from=$threads item=thread}
        <tr>
          <td>
            {if $thread->is_pinned}<span class="label label-warning">Pinned</span>{/if}
            {if $thread->is_locked}<span class="label label-default">Locked</span>{/if}
            <a href="index.php?m=clientforum&forumaction=thread&thread_id={$thread->id}">{$thread->title|escape}</a>
          </td>
          <td style="text-align:center;">{$thread->post_count - 1}</td>
          <td style="text-align:center;">{$thread->view_count}</td>
          <td>{$thread->last_post_at}</td>
        </tr>
      {/foreach}
    </tbody>
  </table>

  <a href="index.php?m=clientforum">&larr; Back to Forum</a>
</div>
