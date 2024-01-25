<?php
$edit = $this->request->params['action'] === 'edit' ? true : false;
$fields = [
    [
        'field' => 'object_type',
        'class' => 'span2',
        'disabled' => !empty($this->data[$modelSelection]['object_type']),
        'default' => empty($this->data[$modelSelection]['object_type']) ? null : $this->data[$modelSelection]['object_type'],
        'options' => $dropdownData['valid_targets'],
        'type' => 'dropdown',
        'stayInLine' => 1
    ],
    [
        'field' => 'object_uuid',
        'class' => 'span4',
        'disabled' => !empty($this->data[$modelSelection]['object_uuid']),
        'default' => empty($this->data[$modelSelection]['object_uuid']) ? null : $this->data[$modelSelection]['object_uuid']
    ],
    [
        'field' => 'distribution',
        'class' => 'input',
        'options' => $dropdownData['distributionLevels'],
        'default' => isset($attribute['Attribute']['distribution']) ? $attribute['Attribute']['distribution'] : $initialDistribution,
        'stayInLine' => 1,
        'type' => 'dropdown'
    ],
    [
        'field' => 'sharing_group_id',
        'class' => 'input',
        'options' => $dropdownData['sgs'],
        'label' => __("Sharing Group"),
        'type' => 'dropdown'
    ]
];

if ($modelSelection === 'Note') {
    $fields = array_merge($fields,
        [
            [
                'field' => 'language',
                'class' => 'span3'
            ],
            [
                'field' => 'note',
                'type' => 'textarea',
                'class' => 'input span6'
            ]
        ]
    );
} else if ($modelSelection === 'Opinion') {
    $fields = array_merge($fields,
        [
            [
                'field' => 'opinion',
                'class' => '',
                'type' => 'opinion'
            ],
            [
                'field' => 'comment',
                'type' => 'textarea',
                'class' => 'input span6'
            ]
        ]
    );
} else if ($modelSelection === 'Relationship') {
    $fields = array_merge($fields,
        [
            [
                'field' => 'relationship_type',
                'class' => 'span4',
            ],
            [
                'field' => 'related_object_type',
                'class' => 'span2',
                'options' => $dropdownData['valid_targets'],
                'type' => 'dropdown',
                'stayInLine' => 1
            ],
            [
                'field' => 'related_object_uuid',
                'class' => 'span4',
            ],
            sprintf('<div><label>%s:</label><div id="related-object-container">%s</div></div>', __('Related Object'), __('- No UUID provided -'))
        ]
    );
}
echo $this->element('genericElements/Form/genericForm', [
    'data' => [
        'description' => false,
        'model' => $modelSelection,
        'title' => $edit ? __('Edit %s', $modelSelection) : __('Add new %s', $modelSelection),
        'fields' => $fields,
        'submit' => [
            'action' => $this->request->params['action'],
            'ajaxSubmit' => 'submitGenericFormInPlace(analystDataSubmitSuccess, true);'
        ]
    ]
]);

if (!$ajax) {
    echo $this->element('/genericElements/SideMenu/side_menu', $menuData);
}
?>

<script>
    function analystDataSubmitSuccess(data) {
        <?php if ($edit): ?>
            replaceNoteInUI(data)
        <?php else: ?>
            addNoteInUI(data)
        <?php endif; ?>
    }

    function replaceNoteInUI(data) {
        var noteType = Object.keys(data)[0]
        var noteHTMLID = '#' + data[noteType].note_type_name + '-' + data[noteType].id
        var $noteToReplace = $(noteHTMLID)
        if ($noteToReplace.length == 1) {
            var relatedObjects = {}
            if (noteType == 'Relationship') {
                var relationship = data[noteType]
                relatedObjects[relationship['object_type']] = {}
                relatedObjects[relationship['object_type']][relationship['related_object_uuid']] = relationship['related_object'][relationship['object_type']]
            }
            var compiledUpdatedNote = renderNote(data[noteType], relatedObjects)
            $noteToReplace[0].outerHTML = compiledUpdatedNote
            $(noteHTMLID).css({'opacity': 0})
            setTimeout(() => {
                $(noteHTMLID).css({'opacity': 1})
            }, 750);
        }
    }

    function addNoteInUI(data) {
        location.reload()
    }

    function displayRelatedObject(data) {
        if (Object.keys(data).length == 0) {
            $('#related-object-container').html('<span class="text-muted"><?= __('Could not fetch remote object') ?></span>')
        } else {
            var parsed = syntaxHighlightJson(data)
            $('#related-object-container').html(parsed)
        }
    }

    function fetchAndDisplayRelatedObject(type, uuid) {
        var url = baseurl + '/analystData/getRelatedElement/' + type + '/' + uuid
        $.ajax({
            type: "get",
            url: url,
            headers: { Accept: "application/json" },
            success: function (data) {
                console.log(data);
                displayRelatedObject(data)
            },
            error: function (data, textStatus, errorThrown) {
                showMessage('fail', textStatus + ": " + errorThrown);
            }
        });
    }

    $(document).ready(function() {
        $('#RelationshipRelatedObjectType').change(function(e) {
            if ($('#RelationshipRelatedObjectUuid').val().length == 36) {
                fetchAndDisplayRelatedObject($('#RelationshipRelatedObjectType').val(),$('#RelationshipRelatedObjectUuid').val())
            }
        })
        $('#RelationshipRelatedObjectUuid').on('input', function(e) {
            if ($('#RelationshipRelatedObjectUuid').val().length == 36) {
                fetchAndDisplayRelatedObject($('#RelationshipRelatedObjectType').val(),$('#RelationshipRelatedObjectUuid').val())
            }
        })
    })
</script>

<style>
    #related-object-container {
        box-shadow: 0 0 5px 0px #22222266;
        padding: 0.5rem;
        max-height: 400px;
        overflow: auto;
        margin-bottom: 1rem;
    }
</style>