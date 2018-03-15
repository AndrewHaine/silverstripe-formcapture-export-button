<?php

class FormCaptureExportButton extends GridFieldExportButton
{

	/**
	 * {@inheritdoc}
	 */
	public function handleExport($gridField, $request = null) {

		$types = $this->getSubmissionTypes();

		return $this->startExport($types);
	}

	protected function startExport($types = []) {

		$exportJob = new FormCaptureCSVExportJob();

		// Set a list of types
		$exportJob->setSubmissionTypes($types);

		singleton('QueuedJobService')->queueJob($exportJob);

		return Controller::curr()->redirectBack();

	}

	/**
	 * Get a list of captured form submission types
	 * @return array
	 */
	protected function getSubmissionTypes()
	{
		$types = CapturedFormSubmission::get()->column('Type');

		return $types;
	}

}
