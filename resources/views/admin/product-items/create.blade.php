@extends('layouts.admin')

@section('title', 'Item Baru')
@section('page-title', 'Item Baru')

@section('content')
    <div class="max-w-2xl rounded-xl border border-slate-800 bg-slate-800/40 p-5">
        @include('admin.product-items.form')
    </div>
@endsection
