@extends('layouts.admin')

@section('title', 'Edit Kategori')
@section('page-title', 'Edit Kategori')

@section('content')
    <div class="max-w-2xl rounded-xl border border-slate-800 bg-slate-800/40 p-5">
        @include('admin.categories.form')
    </div>
@endsection
