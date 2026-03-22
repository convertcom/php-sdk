@extends('layout')

@section('content')
    <h1>{{ $title }}</h1>

    @if($feature)
        <div class="alert alert-success mt-3">Feature Enabled!</div>
    @endif

    <div class="list-group mt-4">
        <div class="list-group-item list-group-item-action active">
            <h5 class="mb-1">List of variations</h5>
            <small>{{ count($variations) }} variation(s) bucketed</small>
        </div>
        @forelse($variations as $variation)
            <div class="list-group-item list-group-item-action">
                <h5 class="mb-1">{{ $variation->variationKey }}</h5>
                <p class="mb-1">
                    Experience: <em>{{ $variation->experienceKey }}</em>
                </p>
                <small class="text-muted">
                    Variation ID: {{ $variation->variationId }} &middot;
                    Experience ID: {{ $variation->experienceId }}
                </small>
            </div>
        @empty
            <div class="list-group-item">
                <p class="text-muted mb-0">No variations bucketed at this location.</p>
            </div>
        @endforelse
    </div>

    <div class="card mt-4">
        <div class="card-body">
            <h5 class="card-title">Track Conversion</h5>
            <form method="POST" action="/api/buy">
                @csrf
                <input type="hidden" name="goalKey" value="{{ $goalKey }}">
                <button type="submit" class="btn btn-primary">Buy</button>
            </form>
        </div>
    </div>
@endsection
