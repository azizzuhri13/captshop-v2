@extends('layouts.admin')

@section('title', 'Kupon Baru')
@section('page-title', 'Kupon Baru')

@section('content')
    <div class="max-w-2xl rounded-xl border border-slate-800 bg-slate-800/40 p-5">
        @include('admin.coupons.form')
    </div>
@endsection
