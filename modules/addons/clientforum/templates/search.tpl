<div class="clientforum">
  <h2>Search the Forum</h2>

  <form method="get" class="cf-search-form">
    <input type="hidden" name="m" value="clientforum">
    <input type="hidden" name="forumaction" value="search">
    <input type="text" name="q" value="{$query|escape}" placeholder="Search titles and posts..." class="form-control" style="max-width:400px;display:inline-block;">
    <button type="submit" class="btn btn-primary">Search</button>
  </form>

  {if $query === ''}
    <p class="cf-search-hint">Enter a search term above.</p>
  {elseif $results|@count == 0}
    <div class="alert alert-info">No threads found matching "{$query|escape}".</div>
  {else}
    <p class="cf-search-hint">{$results|@count} result{if $results|@count != 1}s{/if} for "{$query|escape}"</p>
    <table class="table cf-thread-table">
      <thead>
        <tr>
          <th>Thread</th>
          <th style="width:160px;">Board</th>
          <th style="width:160px;">Last Activity</th>
        </tr>
      </thead>
      <tbody>
        {foreach from=$results item=thread}
          <tr>
            <td>
              {if $thread->is_pinned}<span class="cf-label cf-label-warning">Pinned</span>{/if}
              {if $thread->is_locked}<span class="cf-label cf-label-default">Locked</span>{/if}
              <a href="index.php?m=clientforum&forumaction=thread&thread_id={$thread->id}">{$thread->title|escape}</a>
            </td>
            <td>{$thread->board_name|escape}</td>
            <td>{$thread->last_post_at}</td>
          </tr>
        {/foreach}
      </tbody>
    </table>
  {/if}

  <a href="index.php?m=clientforum">&larr; Back to Forum</a>
</div>
