<?php
    global $path;
?>

<script type="text/javascript" src="<?php echo $path; ?>Modules/solar/Views/solar.js"></script>
<script type="text/javascript" src="<?php echo $path; ?>Lib/tablejs/table.js?v=<?php echo $version; ?>"></script>
<script type="text/javascript" src="<?php echo $path; ?>Lib/tablejs/custom-table-fields.js?v=<?php echo $version; ?>"></script>

<style>
    #system-list input[type="text"] {
      width: 88%;
    }
    #system-list td:nth-of-type(1) { width:20%;}
    #system-list td:nth-of-type(3) { width:14px; text-align: center; }
    #system-list td:nth-of-type(4) { width:14px; text-align: center; }
</style>

<div>
    <div id="api-help-header" style="float:right;"><a href="api"><?php echo _('Solar Systems Help'); ?></a></div>
    <div id="local-header"><h2><?php echo _('Solar Systems'); ?></h2></div>

    <div id="system-list"></div>

    <div id="system-none" class="alert alert-block hide" style="margin-top: 20px">
        <h4 class="alert-heading"><?php echo _('No Solar Systems configured'); ?></h4>
        <p>
            <?php echo _('Solar Systems may consist of several photovoltaic installations, for which power forecasts will be generated.'); ?><br>
            <?php echo _('The systems power forecast may be constantly improved through live measurements, where one separate power meterering point is linked to a configured Solar System. This corresponds e.g. to several module installations of different orientations on a building roof, connected to one single inverter. You may want the next link as a guide for generating your request: '); ?><a href="api"><?php echo _('Solar Systems API helper'); ?></a>
        </p>
    </div>
    
    <div id="toolbar_bottom"><hr>
        <button id="system-new" class="btn btn-small" >&nbsp;<i class="icon-plus-sign" ></i>&nbsp;<?php echo _('New System'); ?></button>
    </div>
    <div id="system-loader" class="ajax-loader"></div>
</div>

<?php require "Modules/solar/Views/solar_dialog.php"; ?>

<script>
    const INTERVAL = 10000;
    var path = "<?php echo $path; ?>";
    var modules = <?php echo json_encode($modules); ?>;
    dialog.moduleMeta = modules;
	
    var systems = {};
	
    // Extend table library field types
    for (z in customtablefields) table.fieldtypes[z] = customtablefields[z];
    table.element = "#system-list";
    table.deletedata = false;
    table.fields = {
        'name':{'title':'<?php echo _("Name"); ?>','type':"fixed"},
        'description':{'title':'<?php echo _('Description'); ?>','type':"fixed"},
        // Actions
        'delete-action':{'title':'', 'type':"delete"},
        'config-action':{'title':'', 'type':"iconconfig", 'icon':'icon-wrench'}
    }
    
    function update() {
        var requestTime = (new Date()).getTime();
        solar.list(function(result, textStatus, xhr) {
        	// Offset in ms from local to server time
            table.timeServerLocalOffset = requestTime-(new Date(xhr.getResponseHeader('Date'))).getTime();
            table.data = result;
            
            systems = result;
            if (systems.length != 0) {
                $("#system-none").hide();
                $("#local-header").show();
                $("#api-help-header").show();
            }
            else {
                $("#system-none").show();
                $("#local-header").hide();
                $("#api-help-header").hide();
            }
            draw();
            $('#system-loader').hide();
        });
    }
    
    update();
    
    var updater;
    function updaterStart() {
        clearInterval(updater);
        updater = null;
        if (INTERVAL > 0) updater = setInterval(update, INTERVAL);
    }
    function updaterStop() {
        clearInterval(updater);
        updater = null;
    }
    updaterStart();
    
    //---------------------------------------------------------------------------------------------
    // Draw systems
    //---------------------------------------------------------------------------------------------
    function draw() {
        table.draw();
    }
    
    // --------------------------------------------------------------------------------------------
    // Events
    // --------------------------------------------------------------------------------------------
    $("#system-list").bind("onDelete", function(e,id,row) {
        // Get system of clicked row
        solar.get(id, function(system) {
            dialog.loadSystemDelete(system, row);
        });
    });
    
    $("#system-list").on('click', '.icon-wrench', function() {
        // Get system of clicked row
        var system = table.data[$(this).attr('row')];
        dialog.loadSystemConfig(system);
    });
    
    $("#system-new").on('click', function() {
        dialog.loadSystemConfig();
    });

</script>