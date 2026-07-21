@extends('layouts.app')

@section('title', 'Dashboard - NEX-OMS')

@section('content')
    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">NEX-OMS</h1>
            <div class="text-secondary">Panel startowy systemu OMS</div>
        </div>
        <div class="align-self-lg-center">
            <span class="badge text-bg-secondary">v0.4.0</span>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-12 col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-secondary small mb-2">Nowe</div>
                    <div class="display-6 fw-semibold">{{ $dashboardStats['newOrders'] ?? 0 }}</div>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-secondary small mb-2">Oczekuj&#261;ce</div>
                    <div class="display-6 fw-semibold">{{ $dashboardStats['pending'] ?? 0 }}</div>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-secondary small mb-2">Wys&#322;ane dzisiaj</div>
                    <div class="display-6 fw-semibold">{{ $dashboardStats['shippedToday'] ?? 0 }}</div>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-secondary small mb-2">Anulowane</div>
                    <div class="display-6 fw-semibold">{{ $dashboardStats['cancelled'] ?? 0 }}</div>
                </div>
            </div>
        </div>
    </div>
@endsection
