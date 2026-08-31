@extends('admin.layout')

@section('header_title', 'API Tokens')

@section('content')

@if(session('tokenValue'))
<div class="card bg-light">
    <div class="card-header">
        <h3>Your New Token</h3>
    </div>
    <div class="card-body">
        <p>Make sure to copy your personal access token now. You won't be able to see it again!</p>
        <code style="display:block; padding: 10px; background: #e2e8f0; border-radius: 4px; word-break: break-all;">
            {{ session('tokenValue') }}
        </code>
    </div>
</div>
@endif

<div class="card">
    <div class="card-header">
        <h3>Generate New Token</h3>
    </div>
    <div class="card-body">
        <form action="{{ route('admin.tokens.generate') }}" method="POST" class="form-inline">
            @csrf
            <div class="form-group" style="margin-bottom: 15px;">
                <label for="token_name" style="display:block; margin-bottom: 5px;">Token Name</label>
                <input type="text" name="token_name" id="token_name" class="form-input" placeholder="e.g. OTP Sender" required style="max-width: 300px;">
            </div>
            <button type="submit" class="btn btn-primary">Generate</button>
        </form>
    </div>
</div>

<div class="card mt-4">
    <div class="card-header">
        <h3>Active Tokens</h3>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Last Used</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($tokens as $token)
                <tr>
                    <td>{{ $token->name }}</td>
                    <td>{{ $token->last_used_at ? $token->last_used_at->diffForHumans() : 'Never' }}</td>
                    <td>{{ $token->created_at->format('M d, Y') }}</td>
                    <td>
                        <form action="{{ route('admin.tokens.revoke', $token->id) }}" method="POST" style="display:inline;">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to revoke this token?')">Revoke</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="4" style="text-align: center;">No active tokens found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
