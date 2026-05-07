<div class="wrap">
    <h1>Generate Matches</h1>
    <?php
    $products = wc_get_products([ 'limit' => -1 ]);
    $tournament_team_map = [];
    foreach ( $products as $p ) {
        $tid = (int) $p->get_id();
        $teams = get_posts([
            'post_type'      => 'flms_team',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_key'       => 'flms_tournament_id',
            'meta_value'     => $tid,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);

        $rows = [];
        foreach ( $teams as $t ) {
            $rows[] = [
                'id'    => (int) $t->ID,
                'name'  => $t->post_title,
                'group' => strtoupper( (string) get_post_meta( $t->ID, 'flms_group_' . $tid, true ) ),
            ];
        }
        $tournament_team_map[ $tid ] = $rows;
    }
    ?>
    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
        <input type="hidden" name="action" value="flms_generate_matches">
        <table class="form-table">
            <tr>
                <th><label>Select Tournament</label></th>
                <td>
                    <select name="tournament_id" id="flms-tournament-select" required>
                        <?php
                        foreach($products as $p) {
                            echo '<option value="'.$p->get_id().'">'.$p->get_name().'</option>';
                        }
                        ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label>Format</label></th>
                <td>
                    <select name="format" id="flms-format-select" onchange="flmsToggleGroupField(this.value)">
                        <option value="round_robin">League (Round Robin)</option>
                        <option value="knockout">Knockout</option>
                        <option value="group_knockout">Group Stage + Knockout</option>
                    </select>
                </td>
            </tr>
            <tr id="flms-num-groups-row" style="display:none;">
                <th><label for="flms-num-groups">Number of Groups</label></th>
                <td>
                    <input type="number" name="num_groups" id="flms-num-groups" value="4" min="2" step="1" style="width:80px;">
                    <p class="description">Teams will be distributed evenly across this many groups (e.g. 4 groups for 16 teams = 4 teams/group).</p>
                </td>
            </tr>
            <tr id="flms-overwrite-groups-row" style="display:none;">
                <th><label for="flms-overwrite-groups">Overwrite Existing Group Assignment</label></th>
                <td>
                    <label>
                        <input type="checkbox" id="flms-overwrite-groups" name="overwrite_groups" value="1">
                        Randomly overwrite all team groups for this tournament
                    </label>
                </td>
            </tr>
            <tr id="flms-group-assignment-row" style="display:none;">
                <th><label>Team Group Assignment</label></th>
                <td>
                    <p class="description" style="margin-top:0;">Set each team group here (A/B/C/...). Leave overwrite unchecked to respect these assignments.</p>
                    <div id="flms-group-assignment-box"></div>
                </td>
            </tr>
        </table>
        <?php submit_button('Generate Group Matches'); ?>
    </form>
</div>
<script>
var flmsTournamentTeams = <?php echo wp_json_encode( $tournament_team_map ); ?>;

function flmsToggleGroupField(val) {
    var isHybrid = (val === 'group_knockout');
    document.getElementById('flms-num-groups-row').style.display = isHybrid ? '' : 'none';
    document.getElementById('flms-overwrite-groups-row').style.display = isHybrid ? '' : 'none';
    document.getElementById('flms-group-assignment-row').style.display = isHybrid ? '' : 'none';
    if (isHybrid) {
        flmsRenderGroupAssignmentUI();
    }
}

function flmsRenderGroupAssignmentUI() {
    var formatEl = document.getElementById('flms-format-select');
    if (!formatEl || formatEl.value !== 'group_knockout') return;

    var tournamentEl = document.getElementById('flms-tournament-select');
    var numGroupsEl = document.getElementById('flms-num-groups');
    var box = document.getElementById('flms-group-assignment-box');
    var overwriteEl = document.getElementById('flms-overwrite-groups');
    if (!tournamentEl || !numGroupsEl || !box || !overwriteEl) return;

    var tid = tournamentEl.value;
    var numGroups = Math.max(2, parseInt(numGroupsEl.value || '4', 10));
    var teams = flmsTournamentTeams[tid] || [];
    var disabledAttr = overwriteEl.checked ? ' disabled' : '';

    var groupOptions = '';
    for (var i = 0; i < numGroups; i++) {
        var label = String.fromCharCode(65 + i);
        groupOptions += '<option value="' + label + '">' + label + '</option>';
    }

    if (!teams.length) {
        box.innerHTML = '<p style="margin:8px 0; color:#666;">No teams found in this tournament.</p>';
        return;
    }

    var html = '<table class="widefat striped" style="max-width:680px;"><thead><tr><th>Team</th><th style="width:140px;">Group</th></tr></thead><tbody>';
    for (var t = 0; t < teams.length; t++) {
        var team = teams[t];
        var selected = '';
        html += '<tr><td>' + team.name + '</td><td><select name="group_assignments[' + team.id + ']"' + disabledAttr + '>';
        for (var g = 0; g < numGroups; g++) {
            var optionLabel = String.fromCharCode(65 + g);
            selected = (team.group === optionLabel) ? ' selected' : '';
            html += '<option value="' + optionLabel + '"' + selected + '>' + optionLabel + '</option>';
        }
        html += '</select></td></tr>';
    }
    html += '</tbody></table>';
    box.innerHTML = html;
}

document.getElementById('flms-tournament-select').addEventListener('change', flmsRenderGroupAssignmentUI);
document.getElementById('flms-num-groups').addEventListener('input', flmsRenderGroupAssignmentUI);
document.getElementById('flms-overwrite-groups').addEventListener('change', flmsRenderGroupAssignmentUI);

// Run on page load in case values are persisted by browser
flmsToggleGroupField(document.getElementById('flms-format-select').value);
</script>