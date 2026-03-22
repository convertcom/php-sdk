@extends('layout')

@section('content')
    <h1>{{ $title }}</h1>

    <div class="alert alert-success mt-4">
        <strong>Payment recorded!</strong> The conversion has been tracked via the Convert PHP SDK.
    </div>

    <a href="/" class="btn btn-outline-primary">Back to Home</a>
@endsection
