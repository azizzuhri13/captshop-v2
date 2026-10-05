@extends('layouts.admin')

@section('title', 'Edit Kupon')
@section('page-title', 'Edit Kupon')

@section('content')
    <div class="max-w-2xl rounded-xl border border-slate-800 bg-slate-800/40 p-5">
        @include('admin.coupons.form')
    </div>
@endsection
