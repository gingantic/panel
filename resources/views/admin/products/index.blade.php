@extends('layouts.admin')

@section('title')
    Products
@endsection

@section('content-header')
    <h1>Products<small>Manage the server products available for deployment.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li class="active">Products</li>
    </ol>
@endsection

@section('content')
    <div class="row">
        <div class="col-xs-12">
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title">Product List</h3>
                    <div class="box-tools">
                        <a class="btn btn-sm btn-primary" href="{{ route('admin.products.new') }}">Create New</a>
                    </div>
                </div>

                <div class="box-body table-responsive no-padding">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>CPU</th>
                                <th>RAM</th>
                                <th>Disk</th>
                                <th>Swap</th>
                                <th>Credits</th>
                                <th class="text-center">Nests</th>
                                <th class="text-center">Nodes</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($products as $product)
                                <tr>
                                    <td><code>{{ $product->id }}</code></td>
                                    <td><a href="{{ route('admin.products.view', $product->id) }}">{{ $product->name }}</a></td>
                                    <td>{{ $product->cpu }}</td>
                                    <td>{{ $product->memory }}</td>
                                    <td>{{ $product->disk }}</td>
                                    <td>{{ $product->swap }}</td>
                                    <td>{{ $product->credits }}</td>
                                    <td class="text-center">{{ $product->nests_count ?? $product->nests->count() }}</td>
                                    <td class="text-center">{{ $product->nodes_count ?? $product->nodes->count() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection 