@extends('admin.layout')

@section('header_title', 'Manage Users')

@section('content')
<div class="card">
    <div class="card-header">
        <h3>Registered Users</h3>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Phone</th>
                    <th>Admin</th>
                    <th>Joined</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($users as $user)
                <tr>
                    <td>{{ $user->first_name }} {{ $user->last_name }}</td>
                    <td>{{ $user->username }}</td>
                    <td>{{ $user->phone_number }}</td>
                    <td>
                        @if($user->is_admin)
                            <span class="badge badge-success">Yes</span>
                        @else
                            <span class="badge badge-secondary">No</span>
                        @endif
                    </td>
                    <td>{{ $user->created_at->format('M d, Y') }}</td>
                    <td>
                        <form action="{{ route('admin.users.toggle_admin', $user->id) }}" method="POST" style="display:inline;">
                            @csrf
                            <button type="submit" class="btn btn-sm {{ $user->is_admin ? 'btn-danger' : 'btn-primary' }}" {{ $user->id === auth()->id() ? 'disabled' : '' }}>
                                {{ $user->is_admin ? 'Remove Admin' : 'Make Admin' }}
                            </button>
                        </form>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    
    @if($users->hasPages())
    <div class="pagination-wrapper">
        {{ $users->links() }}
    </div>
    @endif
</div>
@endsection
