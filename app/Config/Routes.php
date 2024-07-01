<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');
$routes->get('about', 'Home::about');
service('auth')->routes($routes);
$routes->get('dashboard', 'Home::dashboard');
$routes->get('add-product', 'Products\ProductsController::addProduct', ['as' => 'addProduct']);
$routes->post('save-prod-detail', 'Products\ProductsController::saveProdDetail', ['as' => 'saveProdDetail']);




