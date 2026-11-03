@extends('layouts.app')
@section('title', 'Historia przesyłki zwrotnej - NEX-OMS')
@section('content')
<div class="container-fluid py-3"><a class="btn btn-sm btn-outline-secondary mb-3" href="{{ route('returns.show', $orderReturn) }}"><i class="bi bi-arrow-left"></i> Powrót do zwrotu</a>@include('returns._parcel-history')</div>
@endsection
