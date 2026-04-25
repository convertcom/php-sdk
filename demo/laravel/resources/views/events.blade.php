@extends('layout')

@section('content')
    <h1>{{ $title }}</h1>

    <div class="row mt-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Experience Bucketing</div>
                <div class="card-body">
                    @if($variation)
                        <p>Bucketed variation: <strong>{{ $variation->variationKey }}</strong></p>
                        <small class="text-muted">
                            Experience: {{ $variation->experienceKey }} &middot;
                            Variation ID: {{ $variation->variationId }}
                        </small>
                    @else
                        <p class="text-muted">Not bucketed into any variation.</p>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Feature Rollout</div>
                <div class="card-body">
                    @if($feature)
                        <p>Feature rollout active: <strong>{{ $feature->variationKey }}</strong></p>
                        @if($callForActionLabel)
                            <button type="button" class="btn btn-primary">{{ $callForActionLabel }}</button>
                        @endif
                    @else
                        <p class="text-muted">No feature rollout active.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
