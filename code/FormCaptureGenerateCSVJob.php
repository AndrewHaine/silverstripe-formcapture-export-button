<?php

use League\Csv\Writer;
use League\Flysystem\Filesystem;
use League\Flysystem\ZipArchive\ZipArchiveAdapter;

class FormCaptureCSVExportJob extends AbstractQueuedJob
{

	public function __construct()
	{

		$this->totalSteps = 1;

		// Place files in a directory
		$now = date("d-m-Y-H-i");
		$this->fileName = "export-$now.zip";
	}

	/**
	 * @return string
	 */
	public function getJobType() {
		return QueuedJob::QUEUED;
	}

	/**
	 * @return string
	 */
	public function getTitle() {
		return "Export all captured form submissions to CSV";
	}

	/**
	 * Set an array of submission types to work with
	 * @param array $types A list of Submission 'Types' to export, each will be it's own CSV File
	 */
	public function setSubmissionTypes($types = [])
	{
		$this->types = $types;
	}

	/**
	 * Define a destination for our exports
	 * (Adapted from the same method in SilverStripe's GenerateCSVJob)
	 */
	protected function getExportPath()
	{
		$base = ASSETS_PATH . '/.formcaptures';
		if (!is_dir($base)) mkdir($base, 0770, true);

		if (!file_exists("$base/.htaccess")) {
		   file_put_contents("$base/.htaccess", "Deny from all\nRewriteRule .* - [F]\n");
		}

		$folder = $base.'/export-'.date("d-m-Y-H-i");
		if (!is_dir($folder)) mkdir($folder, 0770, true);

		return $folder;
	}

	/**
	 * @param DataList $fields
	 * @return array
	 */
	protected function getHeadersForFields(DataList $fields)
	{
		// We don't know the headers, so we query them here
		$headers = $fields->alterDataQuery(function ($dataQuery) {
			$dataQuery->groupBy('Name'); // Quicker than fetching them all and doing array_unique()
		})->column('Name');
		// Try to put the headers in a sane order
		$preferred = [
			'PersonTitle', 'Title', 'FirstName', 'Surname', 'EmailAddress', 'TelephoneNumber',
			'MobileNumber', 'Address1', 'Address2', 'Town', 'County', 'Postcode', 'Country'
		];
		usort($headers, function ($a, $b) use ($preferred) {
			$aKey = array_search($a, $preferred);
			$bKey = array_search($b, $preferred);
			if ($aKey === false) return 1;
			if ($bKey === false) return -1;
			return ($aKey > $bKey) ? 1 : -1;
		});
		// Push "Created", as that's a useful field
		array_unshift($headers, 'Created');
		return $headers;
	}

	/**
	 * Build an array of data from submitted fields, grouped by submission ID
	 *
	 * @param DataList $fields
	 * @return array
	 */
	protected function getDataFromFields(DataList $fields)
	{
		// Use raw database values rather than casted DB fields - much, much faster
		$query = $fields->dataQuery();
		$data = [];
		foreach ($query->execute() as $capturedField) {
			$submissionID = $capturedField['SubmissionID'];
			if (!isset($data[$submissionID])) {
				// Bonus! Created date always comes first
				$data[$submissionID] = [
					'Created' => $capturedField['Created']
				];
			}
			$data[$submissionID][$capturedField['Name']] = $capturedField['Value'];
		}
		return $data;
	}

	public function setup() {

		// We will create a step for each submission type
		$typeLength = sizeof($this->types);
		$this->totalSteps = $typeLength;
	}


	public function process()
	{
		$types = $this->types;
        $currentType = 1;
		$filesystem = new Filesystem(new ZipArchiveAdapter($this->getExportPath() . '/' . $this->fileName));

		foreach($types as $type) {

			$csv = Writer::createFromString('');
			$submissions = CapturedFormSubmission::get()->filter(['Type' => $type]);
			$fields = $submissions->relation('CapturedFields');

			// Fetch header row data and push it to the CSV
			$headers = $this->getHeadersForFields($fields);
			$csv->insertOne($headers);

			$rawData = $this->getDataFromFields($fields);
			// Now we've got an array of _all_ the fields we received in an unknown order...
			foreach ($rawData as $submissionID => $submissionData) {
				$data = [];
				// ...we can loop over it in the same order as the headers
				foreach ($headers as $header) {
					$data[] = isset($submissionData[$header]) ? $submissionData[$header] : null;
				}
				// Push the data to the CSV
				$csv->insertOne($data);
			}

			// Add the CSV file to the ZIP
			$filesystem->put($type . '.csv', $csv->__toString());

            $currentType += 1;
			gc_collect_cycles();
		}


		if($currentType >= $this->totalSteps) {
            return $this->isComplete = true;
        }

	}

}
