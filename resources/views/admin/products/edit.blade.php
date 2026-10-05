@extends('layouts.admin')

@section('title', 'Edit Produk')
@section('page-title', 'Edit Produk')

@section('content')
    <div class="rounded-xl border border-slate-800 bg-slate-800/40 p-5">
        @include('admin.products.form')
    </div>
@endsection
