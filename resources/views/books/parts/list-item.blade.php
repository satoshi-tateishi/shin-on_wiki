@php
    /**
     * @var \BookStack\Entities\Models\Book $book
     */
@endphp
<a href="{{ $book->getUrl() }}" class="book entity-list-item" data-entity-type="book" data-entity-id="{{$book->id}}">
    <div class="entity-list-item-image bg-book" style="background-image: url('{{ $book->coverInfo()->getUrl(250, 250) }}')">
        @icon('book')
    </div>
    <div class="content">
        @if($book->shelves->isNotEmpty())
            <div class="entity-list-item-shelf-names">
                @foreach($book->shelves as $shelf)
                    <span class="shelf-name-text">{{ $shelf->name }}</span>@if(!$loop->last), @endif
                @endforeach
            </div>
        @endif
        <h4 class="entity-list-item-name break-text">{{ $book->name }}</h4>
        <div class="entity-item-snippet">
            <p class="text-muted break-text mb-s text-limit-lines-1">{{ $book->descriptionInfo()->getPlain() }}</p>
        </div>
    </div>
</a>