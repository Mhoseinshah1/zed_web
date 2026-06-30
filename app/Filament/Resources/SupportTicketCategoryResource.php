<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupportTicketCategoryResource\Pages;
use App\Models\SupportTicketCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SupportTicketCategoryResource extends Resource
{
    protected static ?string $model = SupportTicketCategory::class;

    protected static ?string $navigationIcon   = 'heroicon-o-tag';
    protected static ?string $navigationGroup   = 'پشتیبانی';
    protected static ?string $navigationLabel   = 'دسته‌بندی تیکت‌ها';
    protected static ?string $modelLabel        = 'دسته‌بندی تیکت';
    protected static ?string $pluralModelLabel  = 'دسته‌بندی تیکت‌ها';
    protected static ?int    $navigationSort    = 20;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('نام دسته')
                ->required()
                ->maxLength(255),
            Forms\Components\TextInput::make('sort_order')
                ->label('ترتیب نمایش')
                ->numeric()
                ->default(0),
            Forms\Components\Toggle::make('is_active')
                ->label('فعال')
                ->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')->label('#')->sortable()->width('60px'),
                Tables\Columns\TextColumn::make('name')->label('نام دسته')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('tickets_count')->label('تعداد تیکت')->counts('tickets'),
                Tables\Columns\IconColumn::make('is_active')->label('فعال')->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('وضعیت'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('ویرایش'),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('sort_order');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSupportTicketCategories::route('/'),
            'create' => Pages\CreateSupportTicketCategory::route('/create'),
            'edit'   => Pages\EditSupportTicketCategory::route('/{record}/edit'),
        ];
    }
}
