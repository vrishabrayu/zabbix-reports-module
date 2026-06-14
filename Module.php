<?php

namespace Modules\CustomReports;

use Core\CModule;
use APP;
use CMenuItem;

class Module extends CModule {

	public function init(): void {
		APP::Component()->get('menu.main')
			->findOrAdd(_('Reports'))
				->getSubmenu()
				->add((new CMenuItem(_('Device SLA Report')))
					->setAction('customreports.view')
				);
	}
}
