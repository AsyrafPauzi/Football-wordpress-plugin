jQuery(document).ready(function($){

    // Tournament registration: terms modal on checkout
    $(document).on('click', '.flms-checkout-open-terms', function (e) {
        e.preventDefault();
        var $m = $('#flms-checkout-terms-modal');
        if ($m.length) {
            $m.css('display', 'flex');
            $('body').css('overflow', 'hidden');
        }
    });
    $(document).on('click', '#flms-checkout-terms-modal .flms-friendly-modal-overlay, .flms-checkout-terms-modal-close, .flms-checkout-terms-close-btn', function () {
        $('#flms-checkout-terms-modal').hide();
        $('body').css('overflow', '');
    });
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') {
            $('#flms-checkout-terms-modal').hide();
            $('body').css('overflow', '');
        }
    });

    // Only run roster JSON if the roster section exists on the page
    if ($('#flms-roster-section').length === 0) {
        return;
    }

    var newPlayerData = [];

    // --- ADD ROW LOGIC (MOBILE OPTIMIZED HTML) ---
    $('#add-player-row').click(function(){
        
        // We use specific CLASSES here that match flms-style.css
        var html = '<div class="flms-player-row">';
        
        // Row 1: Full Name & Nickname
        html += '<div class="fp-row-top">';
        html += '<input type="text" placeholder="Full Name" class="np-name" required>';
        html += '<input type="text" placeholder="Nickname" class="np-nick">';
        html += '</div>';

        // Row 2: IC, Age, Pos, Remove Button
        html += '<div class="fp-row-bot">';
        // Added styling for IC to highlight importance
        html += '<input type="text" placeholder="IC / Passport (Required)" class="np-ic" required>';
        
        html += '<div class="fp-mini-group">';
        html += '<input type="number" placeholder="Age" class="np-age">';
        html += '<select class="np-pos">';
        html += '<option value="">Pos</option><option value="GK">GK</option><option value="DEF">DEF</option><option value="MID">MID</option><option value="FWD">FWD</option>';
        html += '</select>';
        html += '<button type="button" class="remove-p">X</button>';
        html += '</div>'; // End mini group
        
        html += '</div>'; // End bot row
        html += '</div>'; // End player row

        $('#flms-roster-rows').append(html);
    });

    // --- REMOVE ROW ---
    $(document).on('click', '.remove-p', function(){
        $(this).closest('.flms-player-row').remove();
        updateNewJson();
    });

    // --- UPDATE JSON DATA ON CHANGE ---
    // This watches for typing in ANY input class starting with .np-
    $(document).on('change', '.np-name, .np-nick, .np-ic, .np-age, .np-pos', function(){
        updateNewJson();
    });

    function updateNewJson() {
        newPlayerData = [];
        $('.flms-player-row').each(function(){
            var row = $(this);
            var p = {
                name:     row.find('.np-name').val(),
                nickname: row.find('.np-nick').val(),
                ic:       row.find('.np-ic').val(),
                age:      row.find('.np-age').val(),
                pos:      row.find('.np-pos').val()
            };
            // Only save if Name is present
            if(p.name) newPlayerData.push(p);
        });
        
        // Save stringified JSON to the hidden textarea for PHP to read
        $('#flms_new_players_json').val(JSON.stringify(newPlayerData));
    }
    
    // --- UI UX: LOADING STATES FOR BUTTONS ---

    // 1. Manager Dashboard Buttons (Add Player / Transfer)
    $('.flms-add-player-box form, .flms-transfer-box form').on('submit', function(){
        var btn = $(this).find('button[type="submit"]');
        var originalText = btn.text();
        btn.prop('disabled', true).html('<span class="spinner"></span> Processing...');
    });

    // 2. Scorekeeper Form
    $('.flms-sk-form').on('submit', function(){
        var btn = $(this).find('.sk-save-btn');
        btn.prop('disabled', true).text('Saving Data...');
    });
    
    // 3. Edit Player Modal
    $('#flms-edit-modal form').on('submit', function(){
        var btn = $(this).find('button[type="submit"]');
        btn.prop('disabled', true).text('Saving...');
    });

});