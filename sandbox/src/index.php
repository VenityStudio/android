<?php 

use php\android{ UXView, UXMobileApplication };

$view = new UXView(UXView::HOME_NAME);

UXMobileApplication::addView(UXView::HOME_NAME, $view);
 
