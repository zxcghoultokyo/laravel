<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

class ContractorLayout extends Component
{
    public function render(): View
    {
        return view('contractor.layout');
    }
}
