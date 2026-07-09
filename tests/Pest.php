<?php

declare(strict_types=1);
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Os testes de Feature bootam a aplicação Laravel via Tests\TestCase.
| Os testes de Unit (incl. app/Domain) são PHP puro e não precisam do framework.
|
*/

pest()->extend(TestCase::class)->in('Feature');
