<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TestimonialResource\Pages;
use App\Models\Testimonial;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TestimonialResource extends Resource
{
    protected static ?string $model = Testimonial::class;

    protected static ?string $navigationIcon   = 'heroicon-o-chat-bubble-left-right';
    protected static ?string $navigationGroup   = 'نمایندگان و بازاریابی';
    protected static ?string $navigationLabel   = 'نظرات کاربران';
    protected static ?string $modelLabel        = 'نظر کاربر';
    protected static ?string $pluralModelLabel  = 'نظرات کاربران';
    protected static ?int    $navigationSort    = 40;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->schema([
                Forms\Components\TextInput::make('name')
                    ->label('نام')->required()->maxLength(120),
                Forms\Components\TextInput::make('role')
                    ->label('عنوان / نقش')->maxLength(120)->placeholder('مثال: کاربر پلن حرفه‌ای'),
                Forms\Components\Textarea::make('body')
                    ->label('متن نظر')->required()->rows(4)->columnSpanFull(),
                Forms\Components\Select::make('rating')
                    ->label('امتیاز')
                    ->options([1 => '۱', 2 => '۲', 3 => '۳', 4 => '۴', 5 => '۵'])
                    ->default(5)->native(false),
                Forms\Components\TextInput::make('sort_order')
                    ->label('ترتیب نمایش')->numeric()->default(0),
                Forms\Components\Toggle::make('is_active')
                    ->label('فعال')->default(true),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')->label('#')->sortable()->width('50px'),
                Tables\Columns\TextColumn::make('name')->label('نام')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('role')->label('نقش')->toggleable(),
                Tables\Columns\TextColumn::make('rating')->label('امتیاز')
                    ->formatStateUsing(fn ($state) => str_repeat('★', max(1, min(5, (int) $state)))),
                Tables\Columns\TextColumn::make('body')->label('متن')->limit(50)->wrap()->toggleable(),
                Tables\Columns\IconColumn::make('is_active')->label('فعال')->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('وضعیت'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListTestimonials::route('/'),
            'create' => Pages\CreateTestimonial::route('/create'),
            'edit'   => Pages\EditTestimonial::route('/{record}/edit'),
        ];
    }
}
