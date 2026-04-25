@extends('layout')

@section('content')
    <h1>{{ $title }}</h1>
    <p class="lead">by Convert Team</p>

    <div class="card mt-4">
        <div class="card-body">
            <h5 class="card-title">Tip</h5>
            <p class="card-text">
                Visit the <a href="/events">Events</a> page to see experience bucketing and feature rollout in action.
                The <a href="/pricing">Pricing</a> page demonstrates multiple experiments and conversion tracking.
            </p>
            <p class="card-text text-muted">
                This demo uses the Convert PHP SDK with the same staging project
                (<code>10035569/10034190</code>) as the Node.js demo, so both demos
                produce comparable bucketing results for the same visitor.
            </p>
        </div>
    </div>
@endsection
