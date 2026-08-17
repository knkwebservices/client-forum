<div class="clientforum">
  <h2>Leaderboard</h2>

  {if $myRank.rank}
    <div class="cf-my-rank">You're ranked <strong>#{$myRank.rank}</strong> with <strong>{$myRank.points}</strong> points.</div>
  {else}
    <div class="cf-my-rank cf-my-rank-none">Post a thread or reply to start earning points.</div>
  {/if}

  {if $leaderboard|@count == 0}
    <div class="alert alert-info">No one has earned points yet — be the first.</div>
  {else}
    <table class="table cf-leaderboard-table">
      <thead>
        <tr>
          <th style="width:60px;">Rank</th>
          <th>Member</th>
          <th style="width:100px;text-align:center;">Points</th>
        </tr>
      </thead>
      <tbody>
        {foreach from=$leaderboard item=row name=lb}
          <tr class="{if $row->client_id == $myClientId}cf-leaderboard-me{/if}">
            <td class="cf-rank-cell">
              {if $smarty.foreach.lb.index == 0}🥇
              {elseif $smarty.foreach.lb.index == 1}🥈
              {elseif $smarty.foreach.lb.index == 2}🥉
              {else}{$smarty.foreach.lb.index+1}
              {/if}
            </td>
            <td>{$row->firstname|escape} {$row->lastname|escape}</td>
            <td style="text-align:center;">{$row->points}</td>
          </tr>
        {/foreach}
      </tbody>
    </table>
  {/if}

  <a href="index.php?m=clientforum">&larr; Back to Forum</a>
</div>
