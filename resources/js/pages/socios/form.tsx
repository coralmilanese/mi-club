import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Opcion } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type SocioForm = {
    nro_socio: string;
    apellido: string;
    nombre: string;
    genero: string;
    fecha_nacimiento: string;
    nacionalidad: string;
    dni: string;
    direccion: string;
    ciudad: string;
    provincia: string;
    email: string;
    telefono: string;
    profesion: string;
    categoria: string;
    sede: string;
    fecha_asociacion: string;
    fecha_inicio_actividad: string;
    observaciones: string;
};

interface Props {
    socio: (Partial<Record<keyof SocioForm, string | number | null>> & { id: number }) | null;
    opciones: { categorias: Opcion[]; sedes: Opcion[] };
}

const texto = (v: string | number | null | undefined) => (v === null || v === undefined ? '' : String(v));

export default function SocioFormPage({ socio, opciones }: Props) {
    const editando = socio !== null;
    const form = useForm<SocioForm>({
        nro_socio: texto(socio?.nro_socio),
        apellido: texto(socio?.apellido),
        nombre: texto(socio?.nombre),
        genero: texto(socio?.genero),
        fecha_nacimiento: texto(socio?.fecha_nacimiento),
        nacionalidad: texto(socio?.nacionalidad) || 'Argentino',
        dni: texto(socio?.dni),
        direccion: texto(socio?.direccion),
        ciudad: texto(socio?.ciudad) || 'Santa Rosa',
        provincia: texto(socio?.provincia) || 'La Pampa',
        email: texto(socio?.email),
        telefono: texto(socio?.telefono),
        profesion: texto(socio?.profesion),
        categoria: texto(socio?.categoria) || 'activo',
        sede: texto(socio?.sede) || 'aasr',
        fecha_asociacion: texto(socio?.fecha_asociacion),
        fecha_inicio_actividad: texto(socio?.fecha_inicio_actividad),
        observaciones: texto(socio?.observaciones),
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Socios', href: '/socios' },
        { title: editando ? 'Editar' : 'Nuevo', href: '#' },
    ];

    const enviar: FormEventHandler = (e) => {
        e.preventDefault();
        if (editando) form.put(route('socios.update', socio.id));
        else form.post(route('socios.store'));
    };

    const campo = (id: keyof SocioForm, etiqueta: string, tipo = 'text') => (
        <div className="grid gap-2">
            <Label htmlFor={id}>{etiqueta}</Label>
            <Input id={id} type={tipo} value={form.data[id]} onChange={(e) => form.setData(id, e.target.value)} />
            <InputError message={form.errors[id]} />
        </div>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={editando ? 'Editar socio' : 'Nuevo socio'} />
            <form onSubmit={enviar} className="flex max-w-4xl flex-col gap-6 p-4">
                <h1 className="text-2xl font-semibold">{editando ? 'Editar socio' : 'Nuevo socio'}</h1>

                <section className="grid gap-4 md:grid-cols-2">
                    {campo('apellido', 'Apellido')}
                    {campo('nombre', 'Nombre')}
                    {campo('dni', 'DNI')}
                    {campo('fecha_nacimiento', 'Fecha de nacimiento', 'date')}
                    <div className="grid gap-2">
                        <Label htmlFor="genero">Género</Label>
                        <NativeSelect id="genero" value={form.data.genero} onChange={(e) => form.setData('genero', e.target.value)}>
                            <option value="">—</option>
                            <option value="M">Masculino</option>
                            <option value="F">Femenino</option>
                        </NativeSelect>
                        <InputError message={form.errors.genero} />
                    </div>
                    {campo('nacionalidad', 'Nacionalidad')}
                    {campo('direccion', 'Dirección')}
                    {campo('ciudad', 'Ciudad')}
                    {campo('provincia', 'Provincia')}
                    {campo('email', 'Email', 'email')}
                    {campo('telefono', 'Teléfono')}
                    {campo('profesion', 'Profesión')}
                </section>

                <section className="grid gap-4 md:grid-cols-2">
                    {campo('nro_socio', 'Nº de socio', 'number')}
                    <div className="grid gap-2">
                        <Label htmlFor="categoria">Categoría</Label>
                        <NativeSelect id="categoria" value={form.data.categoria} onChange={(e) => form.setData('categoria', e.target.value)}>
                            {opciones.categorias.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                </option>
                            ))}
                        </NativeSelect>
                        <InputError message={form.errors.categoria} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="sede">Club de pertenencia</Label>
                        <NativeSelect id="sede" value={form.data.sede} onChange={(e) => form.setData('sede', e.target.value)}>
                            {opciones.sedes.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                </option>
                            ))}
                        </NativeSelect>
                        <InputError message={form.errors.sede} />
                    </div>
                    {campo('fecha_asociacion', 'Fecha de asociación', 'date')}
                    {campo('fecha_inicio_actividad', 'Inicio de actividad', 'date')}
                </section>

                <div className="grid gap-2">
                    <Label htmlFor="observaciones">Observaciones</Label>
                    <Textarea id="observaciones" value={form.data.observaciones} onChange={(e) => form.setData('observaciones', e.target.value)} />
                    <InputError message={form.errors.observaciones} />
                </div>

                <div className="flex gap-2">
                    <Button type="submit" disabled={form.processing}>
                        Guardar
                    </Button>
                    <Button variant="outline" asChild>
                        <Link href={editando ? route('socios.show', socio.id) : route('socios.index')}>Cancelar</Link>
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
