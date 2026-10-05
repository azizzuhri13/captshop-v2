@extends('layouts.admin')

@section('title', 'Banner Baru')
@section('page-title', 'Banner Baru')

@section('content')
    <div class="max-w-2xl rounded-xl border border-slate-800 bg-slate-800/40 p-5">
        @include('admin.banners.form')
    </div>
@endsection
