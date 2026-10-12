<div class="clientforum">
  <div class="cf-board-header">
    <h2>Forum</h2>
    <div style="display:flex;gap:8px;align-items:center;">
      <form method="get" style="display:flex;gap:6px;">
        <input type="hidden" name="m" value="clientforum">
        <input type="hidden" name="forumaction" value="search">
        <input type="text" name="q" placeholder="Search..." class="form-control" style="width:160px;">
        <button type="submit" class="btn btn-default">Search</button>
      </form>
      <a class="btn btn-default" href="index.php?m=clientforum&forumaction=leaderboard">🏆 Leaderboard</a>
    </div>
  </div>

  {if $groups|@count == 0}
    <div class="alert alert-info">No forum boards are available to you yet.</div>
  {/if}

  {foreach from=$groups item=group}
    <div class="cf-category">
      <h3>{$group.category->name|escape}</h3>
      <table class="table cf-board-table">
        <thead>
          <tr>
            <th>Board</th>
            <th style="width:100px;text-align:center;">Threads</th>
            <th style="width:100px;text-align:center;">Posts</th>
          </tr>
        </thead>
        <tbody>
          {foreach from=$group.boards item=board}
            <tr>
              <td>
                <a href="index.php?m=clientforum&forumaction=board&board_id={$board->id}">{$board->name|escape}</a>
                {if $board->restricted}<span class="cf-label cf-label-info">Restricted</span>{/if}
                {if $board->description}<div class="cf-board-desc">{$board->description|escape}</div>{/if}
              </td>
              <td style="text-align:center;">{$board->thread_count}</td>
              <td style="text-align:center;">{$board->post_count}</td>
            </tr>
          {/foreach}
        </tbody>
      </table>
    </div>
  {/foreach}
</div>
