@extends('layouts.admin')

@section('title', 'Edit Item')
@section('page-title', 'Edit Item')

@section('content')
    <div class="max-w-2xl rounded-xl border border-slate-800 bg-slate-800/40 p-5">
        @include('admin.product-items.form')
    </div>
@endsection
