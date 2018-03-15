<?php

class FormCaptureAdminExtension extends Extension
{
    /**
     * Update the form submissions gridfield
     * {@inheritdoc}
     */
    public function updateEditForm(Form $form)
    {
        $gridField = $form->Fields()->fieldByName('CapturedFormSubmission');
        $gridField->getConfig()->addComponent(new FormCaptureExportButton('buttons-before-left'));
    }
}
