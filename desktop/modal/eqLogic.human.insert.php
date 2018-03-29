<?php
if (!isConnect()) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
?>
<table class="table table-condensed table-bordered" id="table_mod_insertEqLogicValue_valueEqLogicToMessage">
    <thead>
        <tr>
            <th style="width: 150px;">{{Objet}}</th>
            <th style="width: 150px;">{{Equipement}}</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td class="mod_insertEqLogicValue_jeeObject">
                <select class='form-control'>
                    <option value="-1">{{Aucun}}</option>
                    <?php
foreach (jeeObject::all() as $jeeObject) {
	echo '<option value="' . $jeeObject->getId() . '">' . $jeeObject->getName() . '</option>';
}

?>
               </select>
           </td>
           <td class="mod_insertEqLogicValue_eqLogic"></td>
       </tr>
   </tbody>
</table>
<script>
    function mod_insertEqLogic() {
    }

    mod_insertEqLogic.options = {};
    mod_insertEqLogic.options.eqLogic = {};


    $("#table_mod_insertEqLogicValue_valueEqLogicToMessage").delegate("td.mod_insertEqLogicValue_jeeObject select", 'change', function() {
        mod_insertEqLogic.changeJeeObjectEqLogic($('#table_mod_insertEqLogicValue_valueEqLogicToMessage td.mod_insertEqLogicValue_jeeObject select'), mod_insertEqLogic.options);
    });

    mod_insertEqLogic.setOptions = function(_options) {
        mod_insertEqLogic.options = _options;
        if (!isset(mod_insertEqLogic.options.eqLogic)) {
            mod_insertEqLogic.options.eqLogic = {};
        }
        mod_insertEqLogic.changeJeeObjectEqLogic($('#table_mod_insertEqLogicValue_valueEqLogicToMessage td.mod_insertEqLogicValue_jeeObject select'), mod_insertEqLogic.options);
    }

    mod_insertEqLogic.getValue = function() {
        var jeeObject_name = $('#table_mod_insertEqLogicValue_valueEqLogicToMessage tbody tr:first .mod_insertEqLogicValue_jeeObject select option:selected').html();
        var equipement_name = $('#table_mod_insertEqLogicValue_valueEqLogicToMessage tbody tr:first .mod_insertEqLogicValue_eqLogic select option:selected').html();
        if (equipement_name == undefined) {
            return '';
        }
        return '#[' + jeeObject_name + '][' + equipement_name + ']#';
    }

    mod_insertEqLogic.getId = function() {
        return $('.mod_insertEqLogicValue_eqLogic select').value();
    }

    mod_insertEqLogic.changeJeeObjectEqLogic = function(_select) {
        jeedom.jeeObject.getEqLogic({
            id: _select.value(),
            orderByName : true,
            error: function(error) {
                $('#div_alert').showAlert({message: error.message, level: 'danger'});
            },
            success: function(eqLogics) {
                _select.closest('tr').find('.mod_insertEqLogicValue_eqLogic').empty();
                var selectEqLogic = '<select class="form-control">';
                for (var i in eqLogics) {
                  if (init(mod_insertEqLogic.options.eqLogic.eqType_name, 'all') == 'all' || eqLogics[i].eqType_name == mod_insertEqLogic.options.eqLogic.eqType_name){
                    selectEqLogic += '<option value="' + eqLogics[i].id + '">' + eqLogics[i].name + '</option>';
                }
            }
            selectEqLogic += '</select>';
            _select.closest('tr').find('.mod_insertEqLogicValue_eqLogic').append(selectEqLogic);
        }
    });
    }

    mod_insertEqLogic.changeJeeObjectEqLogic($('#table_mod_insertEqLogicValue_valueEqLogicToMessage td.mod_insertEqLogicValue_jeeObject select'), mod_insertEqLogic.options);
</script>
