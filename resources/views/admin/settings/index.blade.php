@extends('layouts.app')

@section('content')
<div class="container">
    <h1>Settings</h1>

    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <table class="table">
        <thead><tr><th>Key</th><th>Value</th></tr></thead>
        <tbody>
        @foreach($settings as $s)
            <tr>
                <td>{{ $s->key }}</td>
                <td>{{ is_array($s->value) ? implode(',', $s->value) : $s->value }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <h2>Add / Update Setting</h2>
    <form method="post" action="{{ route('admin.settings.store') }}">
        @csrf
        <div class="mb-3">
            <label class="form-label">Key</label>
            <input name="key" class="form-control" required />
        </div>
        <div class="mb-3">
            <label class="form-label">Value (comma-separated)</label>
            <input name="value" class="form-control" required />
        </div>
        <button class="btn btn-primary">Save</button>
    </form>
</div>
@endsection
