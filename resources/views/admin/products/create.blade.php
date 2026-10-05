@extends('layouts.admin')

@section('title', 'Produk Baru')
@section('page-title', 'Produk Baru')

@section('content')
    <div class="rounded-xl border border-slate-800 bg-slate-800/40 p-5">
        @include('admin.products.form')
    </div>
@endsection
