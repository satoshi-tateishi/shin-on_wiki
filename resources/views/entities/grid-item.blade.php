<a href="{{ $entity->getUrl() }}" class="grid-card"
   data-entity-type="{{ $entity->getType() }}" data-entity-id="{{ $entity->id }}">
    <div class="bg-{{ $entity->getType() }} featured-image-container-wrap">
        <div class="featured-image-container" @if($entity->coverInfo()->exists()) style="background-image: url('{{ $entity->coverInfo()->getUrl(250, 250) }}')"@endif>
        </div>
        @icon($entity->getType())
    </div>
    <div class="grid-card-content">
        @if($entity->getType() === 'book' && $entity->shelves->isNotEmpty())
            <div class="grid-card-shelf-names">
                @foreach($entity->shelves as $shelf)
                    <span class="shelf-name-text">{{ $shelf->name }}</span>@if(!$loop->last), @endif
                @endforeach
            </div>
        @endif
        <h2 class="text-limit-lines-2">{{ $entity->name }}</h2>
        <p class="text-muted">{{ $entity->getExcerpt(130) }}</p>
    </div>
</a>