@extends('layout')

@section('title', $page_title)

@section('content')

    {{-- Variable output --}}
    <h1>Hello, {{ $name }}!</h1>

    {{-- Conditional --}}
    @if($show_message)
        <p>The show_message variable is true.</p>
    @else
        <p>The show_message variable is false.</p>
    @endif

    {{-- Loop --}}
    <ul>
        @foreach($items as $item)
            <li>{{ $loop->index + 1 }}. {{ $item }}</li>
        @endforeach
    </ul>

    {{-- Global variable (set via addGlobal) --}}
    <p>Global site_name: {{ $site_name }}</p>

@endsection

@section('footer')
    <p>Rendered by canvas-blade &mdash; {{ $name }}</p>
@endsection